<?php

namespace Tests\Unit\Finance;

use App\Models\FinanceFundMovement;
use App\Models\PlannedOccurrence;
use App\Services\Finance\FinanceAccountService;
use App\Services\Finance\FinanceFundMovementService;
use App\Services\Finance\FinanceFundService;
use App\Services\Finance\FinanceOccurrenceService;
use Tests\Support\FinanceTestCase;

class FinanceFundRecurrenceTest extends FinanceTestCase
{
    public function test_virtual_scheduled_top_up_is_idempotent_and_reversal_rebuilds_occurrence_state(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $account = $this->account($owner);
        app(FinanceAccountService::class)->reconcile($account, $owner, [
            'observed_balance' => '500.0000', 'occurred_on' => '2026-08-01',
            'reason' => 'Opening balance', 'idempotency_key' => 'fund-recurring-opening',
        ]);
        $fund = app(FinanceFundService::class)->create($owner, [
            'name' => 'Emergency', 'fund_type' => 'emergency', 'storage_mode' => 'virtual',
            'account_id' => $account->id, 'funding_account_id' => null, 'category_id' => null,
            'currency' => 'UAH', 'target_mode' => 'explicit', 'target_amount' => '300.0000',
            'deadline' => null, 'note' => null,
            'rule' => ['top_up_mode' => 'fixed', 'fixed_amount' => '100.0000', 'income_percent' => null,
                'expense_months' => null, 'build_months' => null, 'starts_on' => '2026-08-13',
                'monthday' => 13, 'reminder_time' => '10:00'],
        ]);
        $occurrence = $fund->recurringRule->occurrences()->whereDate('occurrence_date', '2026-08-13')->sole();
        $service = app(FinanceOccurrenceService::class);

        [$fact, $created] = $service->setOutcome($owner, $occurrence, 'actual');
        [, $retryCreated] = $service->setOutcome($owner, $occurrence->fresh(), 'actual');
        $this->assertTrue($created);
        $this->assertFalse($retryCreated);
        $this->assertDatabaseCount('finance_fund_movements', 1);
        $this->assertSame('100.0000', app(FinanceFundService::class)->one($owner, $fund, '2026-08')['projection']['saved_amount']);

        app(FinanceFundMovementService::class)->move($owner, $fund, [
            'action' => 'reverse', 'reverses_movement_id' => $fact->finance_fund_movement_id,
            'idempotency_key' => 'reverse-fund-occurrence', 'note' => 'Correction',
        ]);
        $this->assertSame(PlannedOccurrence::STATUS_PLANNED, $occurrence->fresh()->status);
        $this->assertSame('0.0000', app(FinanceFundService::class)->one($owner, $fund, '2026-08')['projection']['saved_amount']);
        $this->assertSame(2, FinanceFundMovement::query()->count());
    }

    public function test_skipped_scheduled_top_up_can_be_cleared_without_money_or_history_residue(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $fund = $this->regularFund($owner, $this->account($owner), [
            'name' => 'Clearable reserve',
            'rule' => [
                'top_up_mode' => 'fixed', 'fixed_amount' => '25.0000',
                'starts_on' => '2026-08-13', 'monthday' => 13,
            ],
        ]);
        $occurrence = $fund->recurringRule->occurrences()
            ->whereDate('occurrence_date', '2026-08-13')->sole();
        $service = app(FinanceOccurrenceService::class);
        [$fact] = $service->setOutcome($owner, $occurrence, 'skipped');

        $this->assertSame(PlannedOccurrence::STATUS_SKIPPED, $occurrence->fresh()->status);
        $service->clearOutcome($owner, $occurrence->fresh());

        $this->assertSame(PlannedOccurrence::STATUS_PLANNED, $occurrence->fresh()->status);
        $this->assertDatabaseMissing('finance_fund_occurrence_facts', ['id' => $fact->id]);
        $this->assertDatabaseCount('finance_fund_movements', 0);
    }
}
