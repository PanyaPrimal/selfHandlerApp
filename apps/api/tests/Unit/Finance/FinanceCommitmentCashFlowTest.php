<?php

namespace Tests\Unit\Finance;

use App\Models\FinanceCounterparty;
use App\Services\Finance\FinanceCashFlowService;
use App\Services\Finance\FinanceDebtService;
use App\Services\Finance\FinanceFundService;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinanceTestCase;

class FinanceCommitmentCashFlowTest extends FinanceTestCase
{
    public function test_fixed_debts_and_emergency_top_up_compose_once_with_explicit_counts(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $expense = $this->category($owner, 'expense');
        $income = $this->category($owner, 'income');
        $counterparty = FinanceCounterparty::factory()->create(['user_id' => $owner->id]);
        $this->debt($owner, $counterparty->id, $account->id, $expense->id, 'owe', '200.0000');
        $this->debt($owner, $counterparty->id, $account->id, $income->id, 'owed_to_me', '100.0000');
        $this->fund($owner, $account->id, '50.0000');

        $projection = app(FinanceCashFlowService::class)->build($owner, '2026-08');
        $this->assertTrue($projection['complete']);
        $this->assertSame('100.0000', $projection['planned_income']);
        $this->assertSame('250.0000', $projection['mandatory_expense']);
        $this->assertSame('-150.0000', $projection['free_cash_flow']);
        $this->assertSame(3, $projection['counts']['total']);
        $this->assertSame(2, $projection['counts']['debt']);
        $this->assertSame(1, $projection['counts']['emergency_fund']);
    }

    public function test_stable_cash_flow_read_query_count_does_not_grow_with_commitments(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $this->fund($owner, $account->id, '10.0000');
        $service = app(FinanceCashFlowService::class);
        $service->build($owner, '2026-08');
        $one = $this->queries(fn () => $service->build($owner, '2026-08'));
        foreach (range(1, 4) as $index) {
            $this->fund($owner, $account->id, (string) (10 + $index));
        }
        $service->build($owner, '2026-08');
        $many = $this->queries(fn () => $service->build($owner, '2026-08'));

        $this->assertSame($one, $many, "cash flow {$one}/{$many}");
    }

    private function debt($owner, int $counterpartyId, int $accountId, int $categoryId, string $direction, string $amount): void
    {
        app(FinanceDebtService::class)->create($owner, [
            'name' => $direction.' debt', 'counterparty_id' => $counterpartyId, 'direction' => $direction,
            'repayment_mode' => 'fixed', 'original_amount' => $amount, 'currency' => 'UAH',
            'originated_on' => '2026-08-01', 'deadline' => null, 'account_id' => $accountId,
            'category_id' => $categoryId, 'purchase_item_id' => null, 'note' => null,
            'schedule' => ['installment_amount' => $amount, 'installment_count' => 1,
                'interval_months' => 1, 'monthday' => 13, 'first_due_on' => '2026-08-13', 'reminder_time' => null],
        ]);
    }

    private function fund($owner, int $accountId, string $amount): void
    {
        app(FinanceFundService::class)->create($owner, [
            'name' => 'Emergency '.$amount, 'fund_type' => 'emergency', 'storage_mode' => 'virtual',
            'account_id' => $accountId, 'funding_account_id' => null, 'category_id' => null,
            'currency' => 'UAH', 'target_mode' => 'explicit', 'target_amount' => '1000.0000',
            'deadline' => null, 'note' => null,
            'rule' => ['top_up_mode' => 'fixed', 'fixed_amount' => $amount, 'income_percent' => null,
                'expense_months' => null, 'build_months' => null, 'starts_on' => '2026-08-13',
                'monthday' => 13, 'reminder_time' => null],
        ]);
    }

    private function queries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
