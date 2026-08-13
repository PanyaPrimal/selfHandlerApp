<?php

namespace Tests\Feature\Planner;

use App\Models\FinanceCounterparty;
use App\Services\Finance\FinanceAccountService;
use App\Services\Finance\FinanceDebtService;
use App\Services\Finance\FinanceFundService;
use App\Services\Planner\FinanceOccurrenceSource;
use App\Services\Planner\SourceRegistry;
use App\Services\RecurrenceMaterializer;
use Tests\Support\FinanceTestCase;

class FinancePlannerIntegrationTest extends FinanceTestCase
{
    public function test_finance_source_is_registered_once_and_reads_one_snapshot_entry(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $operation = $this->recurringOperation($owner, [
            'name' => 'Rent', 'month_days' => [13], 'reminder_time' => '09:00',
        ]);
        app(RecurrenceMaterializer::class)->materialize($operation->recurringRule, '2026-08-13');

        $registry = app(SourceRegistry::class);
        $this->assertSame(1, count(array_filter($registry->names(), fn (string $name): bool => $name === 'finance')));
        $source = collect($registry->all())->first(fn ($candidate): bool => $candidate->name() === 'finance');
        $entry = $source->entriesFor($owner, '2026-08-13')[0];

        $this->assertSame('finance', $entry->source);
        $this->assertSame('Rent', $entry->title);
        $this->assertSame(['actualize', 'skip', 'reschedule'], $entry->actions);
        $this->assertStringStartsWith('/finance?tab=plans&month=2026-08', $entry->meta['action_url']);
        $this->assertStringContainsString('&occurrence=', $entry->meta['action_url']);
    }

    public function test_debt_and_fund_snapshots_are_distinct_actionable_planner_entries(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        app(FinanceAccountService::class)->reconcile($account, $owner, [
            'observed_balance' => '500.0000', 'occurred_on' => '2026-08-01',
            'reason' => 'Opening', 'idempotency_key' => 'planner-020-opening',
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

        $entries = collect(app(FinanceOccurrenceSource::class)
            ->entriesFor($owner, '2026-08-13'))->keyBy(fn ($entry) => $entry->meta['kind']);
        $this->assertSame(['actualize', 'skip', 'reschedule'], $entries['debt']->actions);
        $this->assertSame(['actualize', 'skip', 'reschedule'], $entries['fund']->actions);
        $this->assertStringContainsString('tab=debts', $entries['debt']->meta['action_url']);
        $this->assertStringContainsString('tab=funds', $entries['fund']->meta['action_url']);
        $this->assertTrue($entries['debt']->meta['mandatory']);
        $this->assertTrue($entries['fund']->meta['mandatory']);
    }
}
