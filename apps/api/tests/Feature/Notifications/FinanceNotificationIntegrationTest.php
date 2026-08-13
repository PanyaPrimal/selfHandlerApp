<?php

namespace Tests\Feature\Notifications;

use App\Models\FinanceCounterparty;
use App\Models\InAppNotification;
use App\Services\Finance\FinanceAccountService;
use App\Services\Finance\FinanceBudgetService;
use App\Services\Finance\FinanceDebtService;
use App\Services\Finance\FinanceFundService;
use App\Services\Notifications\NotificationSourceSynchronizer;
use App\Services\RecurrenceMaterializer;
use Carbon\CarbonImmutable;
use Tests\Support\FinanceTestCase;

class FinanceNotificationIntegrationTest extends FinanceTestCase
{
    public function test_finance_settings_default_enabled_and_timed_occurrence_synchronizes_once(): void
    {
        $owner = $this->owner();
        $operation = $this->recurringOperation($owner, [
            'month_days' => [13], 'reminder_time' => '09:00',
        ]);
        app(RecurrenceMaterializer::class)->materialize($operation->recurringRule, '2026-08-13');
        $now = CarbonImmutable::parse('2026-08-13 05:00:00', 'UTC');
        $synchronizer = app(NotificationSourceSynchronizer::class);

        $this->assertTrue($owner->ensureNotificationSettings()->categoryEnabled('finance'));
        $synchronizer->synchronize($owner, $now);
        $synchronizer->synchronize($owner, $now);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', [
            'type' => InAppNotification::TYPE_FINANCE_REMINDER,
            'category' => InAppNotification::CATEGORY_FINANCE,
        ]);
    }

    public function test_budget_thresholds_use_distinct_identities_without_duplicates(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        app(FinanceBudgetService::class)->create($owner, [
            'month' => '2026-08', 'category_id' => $category->id,
            'limit_amount' => '100.0000', 'currency' => 'UAH',
        ]);
        $this->entry($owner, $account, '-101.0000', 'expense', $category, '2026-08-13');
        $synchronizer = app(NotificationSourceSynchronizer::class);
        $now = CarbonImmutable::parse('2026-08-13 12:00:00', 'UTC');

        $synchronizer->synchronize($owner, $now);
        $synchronizer->synchronize($owner, $now);

        $this->assertDatabaseHas('notifications', ['type' => InAppNotification::TYPE_FINANCE_BUDGET_APPROACHING]);
        $this->assertDatabaseHas('notifications', ['type' => InAppNotification::TYPE_FINANCE_BUDGET_EXCEEDED]);
        $this->assertSame(2, InAppNotification::query()->where('category', 'finance')->count());
    }

    public function test_budget_warning_closes_after_correction_and_rearms_without_a_duplicate(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        app(FinanceBudgetService::class)->create($owner, [
            'month' => '2026-08', 'category_id' => $category->id,
            'limit_amount' => '100.0000', 'currency' => 'UAH',
        ]);
        $this->entry($owner, $account, '-80.0000', 'expense', $category, '2026-08-13');
        $synchronizer = app(NotificationSourceSynchronizer::class);
        $now = CarbonImmutable::parse('2026-08-13 12:00:00', 'UTC');
        $synchronizer->synchronize($owner, $now);
        $warning = InAppNotification::query()->where('type', InAppNotification::TYPE_FINANCE_BUDGET_APPROACHING)->sole();

        $this->entry($owner, $account, '80.0000', 'expense', $category, '2026-08-13');
        $synchronizer->synchronize($owner, $now);
        $this->assertSame(InAppNotification::STATUS_CANCELLED, $warning->fresh()->status);

        $this->entry($owner, $account, '-80.0000', 'expense', $category, '2026-08-13');
        $synchronizer->synchronize($owner, $now);
        $this->assertSame(InAppNotification::STATUS_SCHEDULED, $warning->fresh()->status);
        $this->assertSame(1, InAppNotification::query()
            ->where('type', InAppNotification::TYPE_FINANCE_BUDGET_APPROACHING)->count());
    }

    public function test_timed_debt_and_fund_occurrences_use_finance_links_without_duplicates(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        app(FinanceAccountService::class)->reconcile($account, $owner, [
            'observed_balance' => '500.0000', 'occurred_on' => '2026-08-01',
            'reason' => 'Opening', 'idempotency_key' => 'notification-020-opening',
        ]);
        $counterparty = FinanceCounterparty::factory()->create(['user_id' => $owner->id]);
        app(FinanceDebtService::class)->create($owner, [
            'name' => 'Installment', 'counterparty_id' => $counterparty->id, 'direction' => 'owe',
            'repayment_mode' => 'fixed', 'original_amount' => '100.0000', 'currency' => 'UAH',
            'originated_on' => '2026-08-01', 'deadline' => null, 'account_id' => $account->id,
            'category_id' => $category->id, 'purchase_item_id' => null, 'note' => null,
            'schedule' => ['installment_amount' => '100.0000', 'installment_count' => 1,
                'interval_months' => 1, 'monthday' => 13, 'first_due_on' => '2026-08-13', 'reminder_time' => '09:00'],
        ]);
        app(FinanceFundService::class)->create($owner, [
            'name' => 'Reserve', 'fund_type' => 'emergency', 'storage_mode' => 'virtual',
            'account_id' => $account->id, 'funding_account_id' => null, 'category_id' => null,
            'currency' => 'UAH', 'target_mode' => 'explicit', 'target_amount' => '300.0000',
            'deadline' => null, 'note' => null,
            'rule' => ['top_up_mode' => 'fixed', 'fixed_amount' => '50.0000', 'income_percent' => null,
                'expense_months' => null, 'build_months' => null, 'starts_on' => '2026-08-13',
                'monthday' => 13, 'reminder_time' => '10:00'],
        ]);
        $now = CarbonImmutable::parse('2026-08-13 05:00:00', 'UTC');
        $synchronizer = app(NotificationSourceSynchronizer::class);
        $synchronizer->synchronize($owner, $now);
        $synchronizer->synchronize($owner, $now);

        $reminders = InAppNotification::query()->where('type', InAppNotification::TYPE_FINANCE_REMINDER)
            ->orderBy('id')->get();
        $this->assertCount(2, $reminders);
        $this->assertTrue($reminders->contains(fn ($item) => str_contains($item->action_url, 'tab=debts')));
        $this->assertTrue($reminders->contains(fn ($item) => str_contains($item->action_url, 'tab=funds')));
    }
}
