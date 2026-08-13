<?php

namespace Tests\Unit\Finance;

use App\Models\FinanceCounterparty;
use App\Services\Finance\FinanceDebtPaymentService;
use App\Services\Finance\FinanceDebtService;
use Illuminate\Validation\ValidationException;
use Tests\Support\FinanceTestCase;

class FinanceDebtPaymentServiceTest extends FinanceTestCase
{
    public function test_flexible_payment_is_idempotent_and_cannot_exceed_remaining_principal(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $counterparty = FinanceCounterparty::factory()->create(['user_id' => $owner->id]);
        $debt = app(FinanceDebtService::class)->create($owner, [
            'name' => 'Loan', 'counterparty_id' => $counterparty->id, 'direction' => 'owe',
            'repayment_mode' => 'flexible', 'original_amount' => '100.0000', 'currency' => 'UAH',
            'originated_on' => '2026-08-01', 'deadline' => null, 'account_id' => $account->id,
            'category_id' => $category->id, 'purchase_item_id' => null, 'schedule' => null, 'note' => null,
        ]);
        $payload = ['planned_occurrence_id' => null, 'amount' => '60.0000', 'account_id' => $account->id,
            'category_id' => $category->id, 'occurred_on' => '2026-08-13',
            'idempotency_key' => 'debt-payment-idempotent', 'note' => null];

        [$first, $created] = app(FinanceDebtPaymentService::class)->pay($owner, $debt, $payload);
        [$retry, $retried] = app(FinanceDebtPaymentService::class)->pay($owner, $debt, $payload);
        $this->assertTrue($created);
        $this->assertFalse($retried);
        $this->assertSame($first->id, $retry->id);
        $this->assertSame('40.0000', app(FinanceDebtService::class)->one($owner, $debt)['remaining_amount']);

        $this->expectException(ValidationException::class);
        app(FinanceDebtPaymentService::class)->pay($owner, $debt, [
            ...$payload, 'amount' => '40.0001', 'idempotency_key' => 'debt-payment-overpay',
        ]);
    }
}
