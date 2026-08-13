<?php

namespace Tests\Unit\Finance;

use App\Models\FinanceCounterparty;
use App\Services\Finance\FinanceDebtPaymentService;
use App\Services\Finance\FinanceDebtService;
use App\Services\Finance\FinanceLedgerService;
use Carbon\CarbonImmutable;
use Tests\Support\FinanceTestCase;

class FinanceDebtServiceTest extends FinanceTestCase
{
    public function test_flexible_debt_payment_and_reversal_drive_remaining_principal(): void
    {
        CarbonImmutable::setTestNow('2026-08-13 12:00:00 UTC');
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $counterparty = FinanceCounterparty::factory()->create(['user_id' => $owner->id]);
        $debts = app(FinanceDebtService::class);
        $debt = $debts->create($owner, [
            'name' => 'Loan', 'counterparty_id' => $counterparty->id, 'direction' => 'owe',
            'repayment_mode' => 'flexible', 'original_amount' => '1000.0000', 'currency' => 'UAH',
            'originated_on' => '2026-08-01', 'deadline' => '2026-12-31',
            'account_id' => $account->id, 'category_id' => $category->id,
            'purchase_item_id' => null, 'schedule' => null, 'note' => null,
        ]);
        [$payment] = app(FinanceDebtPaymentService::class)->pay($owner, $debt, [
            'planned_occurrence_id' => null, 'amount' => '250.0000',
            'account_id' => $account->id, 'category_id' => $category->id,
            'occurred_on' => '2026-08-13', 'idempotency_key' => 'debt-pay-1', 'note' => null,
        ]);

        $projection = $debts->one($owner, $debt);
        $this->assertSame('250.0000', $projection['paid_amount']);
        $this->assertSame('750.0000', $projection['remaining_amount']);

        app(FinanceLedgerService::class)->reverse($owner, $payment->transactionGroup, [
            'idempotency_key' => 'debt-reverse-1', 'reason' => 'Correction',
        ]);
        $this->assertSame('1000.0000', $debts->one($owner, $debt)['remaining_amount']);
    }

    public function test_fixed_schedule_keeps_count_across_short_months(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $counterparty = FinanceCounterparty::factory()->create(['user_id' => $owner->id]);
        $service = app(FinanceDebtService::class);
        $debt = $service->create($owner, [
            'name' => 'Installments', 'counterparty_id' => $counterparty->id, 'direction' => 'owe',
            'repayment_mode' => 'fixed', 'original_amount' => '300.0000', 'currency' => 'UAH',
            'originated_on' => '2026-01-01', 'deadline' => null,
            'account_id' => $account->id, 'category_id' => $category->id,
            'purchase_item_id' => null, 'note' => null,
            'schedule' => ['installment_amount' => '100.0000', 'installment_count' => 3,
                'interval_months' => 1, 'monthday' => 31, 'first_due_on' => '2026-01-31',
                'reminder_time' => null],
        ]);

        $dates = $service->one($owner, $debt)['occurrences']->pluck('original_due_on')->all();
        $this->assertSame(['2026-01-31', '2026-03-31', '2026-05-31'], $dates);
        $this->assertSame('2026-05-31', $debt->recurringRule()->value('last_materialized_until')->format('Y-m-d'));
    }
}
