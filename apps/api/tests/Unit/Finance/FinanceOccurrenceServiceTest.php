<?php

namespace Tests\Unit\Finance;

use App\Models\FinanceLedgerEntry;
use App\Models\FinanceOccurrenceFact;
use App\Models\FinanceTransactionGroup;
use App\Models\PlannedOccurrence;
use App\Services\Finance\FinanceLedgerService;
use App\Services\Finance\FinanceOccurrenceService;
use App\Services\RecurrenceMaterializer;
use Illuminate\Http\Exceptions\HttpResponseException;
use Tests\Support\FinanceTestCase;

class FinanceOccurrenceServiceTest extends FinanceTestCase
{
    public function test_actualization_is_idempotent_and_debits_one_ordinary_ledger_entry_once(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $operation = $this->recurringOperation($owner, ['month_days' => [13]]);
        app(RecurrenceMaterializer::class)->materialize($operation->recurringRule, '2026-08-13');
        $occurrence = $operation->recurringRule->occurrences()->whereDate('occurrence_date', '2026-08-13')->sole();
        $service = app(FinanceOccurrenceService::class);

        [$first, $created] = $service->setOutcome($owner, $occurrence, 'actual');
        [$retry, $retryCreated] = $service->setOutcome($owner, $occurrence->fresh(), 'actual');

        $this->assertTrue($created);
        $this->assertFalse($retryCreated);
        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, FinanceTransactionGroup::query()->count());
        $this->assertSame('-100.0000', FinanceLedgerEntry::query()->sole()->delta_amount);
        $this->assertSame(PlannedOccurrence::STATUS_DONE, $occurrence->fresh()->status);
    }

    public function test_skip_creates_no_ledger_and_can_be_cleared(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $operation = $this->recurringOperation($owner, ['month_days' => [13]]);
        app(RecurrenceMaterializer::class)->materialize($operation->recurringRule, '2026-08-13');
        $occurrence = $operation->recurringRule->occurrences()->whereDate('occurrence_date', '2026-08-13')->sole();
        $service = app(FinanceOccurrenceService::class);

        $service->setOutcome($owner, $occurrence, 'skipped');
        $this->assertDatabaseCount('finance_transaction_groups', 0);
        $this->assertSame(PlannedOccurrence::STATUS_SKIPPED, $occurrence->fresh()->status);

        $service->clearOutcome($owner, $occurrence->fresh());
        $this->assertDatabaseCount('finance_occurrence_facts', 0);
        $this->assertSame(PlannedOccurrence::STATUS_PLANNED, $occurrence->fresh()->status);
    }

    public function test_actual_outcome_cannot_be_cleared(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $operation = $this->recurringOperation($owner, ['month_days' => [13]]);
        app(RecurrenceMaterializer::class)->materialize($operation->recurringRule, '2026-08-13');
        $occurrence = $operation->recurringRule->occurrences()->whereDate('occurrence_date', '2026-08-13')->sole();
        $service = app(FinanceOccurrenceService::class);
        $service->setOutcome($owner, $occurrence, 'actual');

        $this->expectException(HttpResponseException::class);
        $service->clearOutcome($owner, $occurrence->fresh());
    }

    public function test_reversal_corrects_money_without_rewriting_the_actual_outcome(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $operation = $this->recurringOperation($owner, ['month_days' => [13]]);
        app(RecurrenceMaterializer::class)->materialize($operation->recurringRule, '2026-08-13');
        $occurrence = $operation->recurringRule->occurrences()->whereDate('occurrence_date', '2026-08-13')->sole();
        [$fact] = app(FinanceOccurrenceService::class)->setOutcome($owner, $occurrence, 'actual');

        app(FinanceLedgerService::class)->reverse($owner, $fact->transactionGroup, [
            'idempotency_key' => 'reverse-finance-occurrence', 'reason' => 'Corrected plan',
        ]);

        $this->assertSame(FinanceOccurrenceFact::OUTCOME_ACTUAL, $fact->fresh()->outcome);
        $this->assertSame(PlannedOccurrence::STATUS_DONE, $occurrence->fresh()->status);
        $this->assertSame('0.0000', FinanceLedgerEntry::query()->pluck('delta_amount')->reduce(
            fn (string $sum, mixed $amount): string => bcadd($sum, (string) $amount, 4),
            '0.0000',
        ));
    }
}
