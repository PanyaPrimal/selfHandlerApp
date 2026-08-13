<?php

namespace Tests\Feature\Notifications;

use App\Models\InAppNotification;
use App\Services\Finance\FinanceBudgetService;
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
}
