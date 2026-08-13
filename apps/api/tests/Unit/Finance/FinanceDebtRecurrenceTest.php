<?php

namespace Tests\Unit\Finance;

use App\Models\FinanceCounterparty;
use App\Models\FinanceDebtPaymentFact;
use App\Models\PlannedOccurrence;
use App\Services\Finance\FinanceDebtService;
use App\Services\Finance\FinanceLedgerService;
use App\Services\Finance\FinanceOccurrenceService;
use Tests\Support\FinanceTestCase;

class FinanceDebtRecurrenceTest extends FinanceTestCase
{
    public function test_actual_retry_reversal_and_corrected_repayment_keep_history_and_one_active_attempt(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $counterparty = FinanceCounterparty::factory()->create(['user_id' => $owner->id]);
        $debt = app(FinanceDebtService::class)->create($owner, [
            'name' => 'Two installments', 'counterparty_id' => $counterparty->id, 'direction' => 'owe',
            'repayment_mode' => 'fixed', 'original_amount' => '200.0000', 'currency' => 'UAH',
            'originated_on' => '2026-08-01', 'deadline' => null, 'account_id' => $account->id,
            'category_id' => $category->id, 'purchase_item_id' => null, 'note' => null,
            'schedule' => ['installment_amount' => '100.0000', 'installment_count' => 2,
                'interval_months' => 1, 'monthday' => 13, 'first_due_on' => '2026-08-13', 'reminder_time' => '09:00'],
        ]);
        $occurrence = $debt->recurringRule->occurrences()->whereDate('occurrence_date', '2026-08-13')->sole();
        $service = app(FinanceOccurrenceService::class);

        [$first, $created] = $service->setOutcome($owner, $occurrence, 'actual');
        [$retry, $retryCreated] = $service->setOutcome($owner, $occurrence->fresh(), 'actual');
        $this->assertTrue($created);
        $this->assertFalse($retryCreated);
        $this->assertSame($first->id, $retry->id);

        app(FinanceLedgerService::class)->reverse($owner, $first->transactionGroup, [
            'idempotency_key' => 'reverse-debt-occurrence', 'reason' => 'Wrong account',
        ]);
        $this->assertSame(PlannedOccurrence::STATUS_PLANNED, $occurrence->fresh()->status);

        [$corrected, $correctedCreated] = $service->setOutcome($owner, $occurrence->fresh(), 'actual');
        $this->assertTrue($correctedCreated);
        $this->assertNotSame($first->id, $corrected->id);
        $this->assertSame(2, FinanceDebtPaymentFact::query()
            ->where('planned_occurrence_id', $occurrence->id)->count());
        $this->assertSame($corrected->id, $occurrence->fresh()->finance_debt_payment_fact_id);
        $this->assertSame('100.0000', app(FinanceDebtService::class)->one($owner, $debt)['remaining_amount']);
    }
}
