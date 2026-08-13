<?php

namespace Tests\Unit\Finance;

use App\Models\FinanceExchangeRate;
use App\Services\Finance\FinanceCashFlowService;
use App\Services\Finance\FinanceOccurrenceService;
use App\Services\RecurrenceMaterializer;
use Tests\Support\FinanceTestCase;

class FinanceCashFlowServiceTest extends FinanceTestCase
{
    public function test_monthly_cash_flow_groups_income_mandatory_discretionary_and_free_exactly(): void
    {
        $owner = $this->owner(baseCurrency: 'UAH');
        $this->recurringOperation($owner, ['month_days' => [5], 'amount' => '100.0000'], 'income', 'USD');
        $this->recurringOperation($owner, ['month_days' => [15], 'amount' => '1200.0000'], 'expense', 'UAH');
        $this->recurringOperation($owner, [
            'month_days' => [25], 'amount' => '300.0000', 'mandatory' => false,
        ], 'expense', 'UAH');
        FinanceExchangeRate::factory()->create([
            'user_id' => $owner->id, 'from_currency' => 'USD', 'to_currency' => 'UAH',
            'rate_date' => '2026-08-01', 'rate' => '40.000000000000',
        ]);

        $projection = app(FinanceCashFlowService::class)->build($owner, '2026-08');

        $this->assertTrue($projection['complete']);
        $this->assertSame('4000.0000', $projection['planned_income']);
        $this->assertSame('1200.0000', $projection['mandatory_expense']);
        $this->assertSame('300.0000', $projection['discretionary_expense']);
        $this->assertSame('2800.0000', $projection['free_cash_flow']);
    }

    public function test_any_missing_nonzero_rate_nulls_all_consolidated_amounts(): void
    {
        $owner = $this->owner(baseCurrency: 'UAH');
        $this->recurringOperation($owner, ['month_days' => [5]], 'income', 'USD');
        $projection = app(FinanceCashFlowService::class)->build($owner, '2026-08');

        $this->assertFalse($projection['complete']);
        $this->assertSame(['USD'], $projection['missing_currencies']);
        foreach (['planned_income', 'mandatory_expense', 'discretionary_expense', 'free_cash_flow'] as $key) {
            $this->assertNull($projection[$key], $key);
        }
    }

    public function test_actual_remains_planned_money_while_skipped_is_excluded_and_counts_are_explicit(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $actualOperation = $this->recurringOperation($owner, ['month_days' => [13], 'amount' => '100.0000']);
        $skippedOperation = $this->recurringOperation($owner, ['month_days' => [13], 'amount' => '25.0000']);
        foreach ([$actualOperation, $skippedOperation] as $operation) {
            app(RecurrenceMaterializer::class)->materialize($operation->recurringRule, '2026-08-13');
        }
        $actual = $actualOperation->recurringRule->occurrences()->whereDate('occurrence_date', '2026-08-13')->sole();
        $skipped = $skippedOperation->recurringRule->occurrences()->whereDate('occurrence_date', '2026-08-13')->sole();
        $outcomes = app(FinanceOccurrenceService::class);
        $outcomes->setOutcome($owner, $actual, 'actual');
        $outcomes->setOutcome($owner, $skipped, 'skipped');

        $projection = app(FinanceCashFlowService::class)->build($owner, '2026-08');

        $this->assertSame('100.0000', $projection['mandatory_expense']);
        $this->assertSame(2, $projection['counts']['total']);
        $this->assertSame(1, $projection['counts']['actual']);
        $this->assertSame(1, $projection['counts']['skipped']);
        $this->assertSame(0, $projection['counts']['planned']);
    }
}
