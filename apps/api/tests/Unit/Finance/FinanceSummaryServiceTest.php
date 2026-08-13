<?php

namespace Tests\Unit\Finance;

use App\Models\FinanceExchangeRate;
use App\Services\Finance\FinanceLedgerService;
use App\Services\Finance\FinanceSummaryService;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinanceTestCase;

class FinanceSummaryServiceTest extends FinanceTestCase
{
    public function test_summary_consolidates_historically_and_excludes_transfer_adjustment_cash_flow(): void
    {
        $owner = $this->owner(baseCurrency: 'UAH');
        $uah = $this->account($owner);
        $usd = $this->account($owner, 'USD');
        $income = $this->category($owner, 'income');
        $expense = $this->category($owner, 'expense');
        FinanceExchangeRate::factory()->create([
            'user_id' => $owner->id, 'from_currency' => 'USD', 'to_currency' => 'UAH',
            'rate_date' => '2026-08-01', 'rate' => '40.000000000000',
        ]);
        $ledger = app(FinanceLedgerService::class);
        [$incomeGroup] = $ledger->postActual($owner, [
            'idempotency_key' => 'summary-income', 'kind' => 'income', 'account_id' => $usd->id,
            'category_id' => $income->id, 'amount' => '10.0000', 'occurred_on' => '2026-08-02',
            'note' => null, 'tag' => null,
        ]);
        $ledger->postActual($owner, [
            'idempotency_key' => 'summary-expense', 'kind' => 'expense', 'account_id' => $uah->id,
            'category_id' => $expense->id, 'amount' => '50.0000', 'occurred_on' => '2026-08-03',
            'note' => null, 'tag' => null,
        ]);
        $this->entry($owner, $uah, '5.0000', 'adjustment', null, '2026-08-04');
        $ledger->transfer($owner, [
            'idempotency_key' => 'summary-transfer', 'source_account_id' => $uah->id,
            'destination_account_id' => $usd->id, 'source_amount' => '40.0000',
            'destination_amount' => '1.0000', 'occurred_on' => '2026-08-05', 'note' => null, 'tag' => null,
        ]);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $summary = app(FinanceSummaryService::class)->build(
            $owner, '2026-08-01', '2026-08-13', '2026-08-13',
        );

        $this->assertSame('355.0000', $summary['consolidated']['total']);
        $this->assertTrue($summary['consolidated']['complete']);
        $this->assertSame('400.0000', $summary['actuals']['income']);
        $this->assertSame('50.0000', $summary['actuals']['expense']);
        $this->assertSame('350.0000', $summary['actuals']['net']);
        $this->assertLessThanOrEqual(8, $queries);

        $ledger->reverse($owner, $incomeGroup, ['idempotency_key' => 'summary-reverse', 'reason' => 'Cancel']);
        $cancelled = app(FinanceSummaryService::class)->build($owner, '2026-08-01', now()->toDateString(), now()->toDateString());
        $this->assertSame('0.0000', $cancelled['actuals']['income']);
    }

    public function test_missing_rate_nulls_whole_consolidated_and_actual_projection(): void
    {
        $owner = $this->owner(baseCurrency: 'UAH');
        $eur = $this->account($owner, 'EUR');
        $income = $this->category($owner, 'income');
        $ledger = app(FinanceLedgerService::class);
        [$group] = $ledger->postActual($owner, [
            'idempotency_key' => 'missing-rate-income', 'kind' => 'income', 'account_id' => $eur->id,
            'category_id' => $income->id, 'amount' => '1.0000', 'occurred_on' => '2026-08-13',
            'note' => null, 'tag' => null,
        ]);

        $summary = app(FinanceSummaryService::class)->build($owner, '2026-08-01', '2026-08-13', '2026-08-13');
        $this->assertFalse($summary['consolidated']['complete']);
        $this->assertNull($summary['consolidated']['total']);
        $this->assertSame(['EUR'], $summary['consolidated']['missing_currencies']);
        $this->assertFalse($summary['actuals']['complete']);
        $this->assertNull($summary['actuals']['income']);
        $this->assertNull($summary['actuals']['expense']);
        $this->assertNull($summary['actuals']['net']);

        $ledger->reverse($owner, $group, ['idempotency_key' => 'missing-rate-reversal', 'reason' => 'Cancelled']);
        $cancelled = app(FinanceSummaryService::class)->build($owner, '2026-08-01', '2026-08-13', '2026-08-13');
        $this->assertTrue($cancelled['consolidated']['complete']);
        $this->assertSame('0.0000', $cancelled['consolidated']['total']);
        $this->assertTrue($cancelled['actuals']['complete']);
        $this->assertSame('0.0000', $cancelled['actuals']['income']);
    }
}
