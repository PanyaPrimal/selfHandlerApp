<?php

namespace Tests\Unit\Finance;

use App\Models\FinanceCounterparty;
use App\Services\Finance\FinanceAccountService;
use App\Services\Finance\FinanceDebtPaymentService;
use App\Services\Finance\FinanceDebtService;
use App\Services\Finance\FinanceFundMovementService;
use App\Services\Finance\FinanceFundService;
use App\Services\Finance\FinanceGoalService;
use App\Services\Finance\FinanceLedgerService;
use Tests\Support\FinanceTestCase;

class FinanceGoalProgressServiceTest extends FinanceTestCase
{
    public function test_save_progress_uses_fund_value_and_clamps_overfunding_to_one(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        app(FinanceAccountService::class)->reconcile($account, $owner, [
            'observed_balance' => '500.0000', 'occurred_on' => '2026-08-01',
            'reason' => 'Opening', 'idempotency_key' => 'goal-clamp-opening',
        ]);
        $fund = app(FinanceFundService::class)->create($owner, $this->fundPayload($account->id, '100.0000'));
        $goal = app(FinanceGoalService::class)->create($owner, [
            'name' => 'Small reserve', 'description' => null, 'target_date' => null, 'kind' => 'save',
            'saving_fund_id' => $fund->id, 'debt_id' => null,
            'milestones' => [['target_value' => '100.0000', 'target_date' => null]],
        ]);
        app(FinanceFundMovementService::class)->move($owner, $fund, [
            'action' => 'top_up', 'amount' => '150.0000', 'counterparty_account_id' => null,
            'occurred_on' => '2026-08-13', 'idempotency_key' => 'goal-clamp-topup', 'note' => null,
        ]);

        $projection = app(FinanceGoalService::class)->one($owner, $goal);
        $this->assertSame('150.0000', $projection['current_value']);
        $this->assertSame('0.0000', $projection['remaining_value']);
        $this->assertSame(1.0, $projection['progress']);
        $this->assertTrue($projection['milestones'][0]['achieved']);
    }

    public function test_pay_off_progress_moves_only_with_active_principal_payments_and_reversal(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $counterparty = FinanceCounterparty::factory()->create(['user_id' => $owner->id]);
        $debt = app(FinanceDebtService::class)->create($owner, [
            'name' => 'Loan', 'counterparty_id' => $counterparty->id, 'direction' => 'owe',
            'repayment_mode' => 'flexible', 'original_amount' => '1000.0000', 'currency' => 'UAH',
            'originated_on' => '2026-08-01', 'deadline' => null, 'account_id' => $account->id,
            'category_id' => $category->id, 'purchase_item_id' => null, 'schedule' => null, 'note' => null,
        ]);
        $goal = app(FinanceGoalService::class)->create($owner, [
            'name' => 'Debt free', 'description' => null, 'target_date' => null, 'kind' => 'pay_off',
            'saving_fund_id' => null, 'debt_id' => $debt->id,
            'milestones' => [['target_value' => '700.0000', 'target_date' => null]],
        ]);
        [$payment] = app(FinanceDebtPaymentService::class)->pay($owner, $debt, [
            'planned_occurrence_id' => null, 'amount' => '300.0000', 'account_id' => $account->id,
            'category_id' => $category->id, 'occurred_on' => '2026-08-13',
            'idempotency_key' => 'goal-payoff-payment', 'note' => null,
        ]);

        $paid = app(FinanceGoalService::class)->one($owner, $goal);
        $this->assertSame('700.0000', $paid['current_value']);
        $this->assertSame(0.3, $paid['progress']);
        $this->assertTrue($paid['milestones'][0]['achieved']);

        app(FinanceLedgerService::class)->reverse($owner, $payment->transactionGroup, [
            'idempotency_key' => 'goal-payoff-reverse', 'reason' => 'Correction',
        ]);
        $reversed = app(FinanceGoalService::class)->one($owner, $goal);
        $this->assertSame('1000.0000', $reversed['current_value']);
        $this->assertSame(0.0, $reversed['progress']);
        $this->assertFalse($reversed['milestones'][0]['achieved']);
    }

    /** @return array<string,mixed> */
    private function fundPayload(int $accountId, string $target): array
    {
        return [
            'name' => 'Reserve', 'fund_type' => 'regular', 'storage_mode' => 'virtual',
            'account_id' => $accountId, 'funding_account_id' => null, 'category_id' => null,
            'currency' => 'UAH', 'target_mode' => 'explicit', 'target_amount' => $target,
            'deadline' => null, 'rule' => ['top_up_mode' => 'none', 'fixed_amount' => null,
                'income_percent' => null, 'expense_months' => null, 'build_months' => null,
                'starts_on' => null, 'monthday' => null, 'reminder_time' => null], 'note' => null,
        ];
    }
}
