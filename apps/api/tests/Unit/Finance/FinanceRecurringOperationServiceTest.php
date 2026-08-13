<?php

namespace Tests\Unit\Finance;

use App\Models\FinanceOccurrenceFact;
use App\Services\Finance\FinanceRecurringOperationService;
use App\Services\RecurrenceMaterializer;
use Illuminate\Validation\ValidationException;
use Tests\Support\FinanceTestCase;

class FinanceRecurringOperationServiceTest extends FinanceTestCase
{
    public function test_create_owns_one_monthly_rule_and_normalized_days(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $account = $this->account($owner, 'UAH');
        $category = $this->category($owner, 'income');
        $operation = app(FinanceRecurringOperationService::class)->create($owner, [
            'name' => 'Salary', 'direction' => 'income', 'account_id' => $account->id,
            'category_id' => $category->id, 'amount' => '3000', 'mandatory' => false,
            'starts_on' => '2026-08-01', 'ends_on' => null, 'interval_months' => 1,
            'month_days' => [25, 5, 15, 5], 'reminder_time' => '09:30',
        ]);

        $this->assertSame('3000.0000', $operation->amount);
        $this->assertSame('monthly', $operation->recurringRule->frequency);
        $this->assertSame([5, 15, 25], $operation->recurringRule->monthdays);
        $this->assertSame('09:30', $operation->recurringRule->slot_time);
    }

    public function test_mandatory_income_and_mismatched_references_are_rejected(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner, 'USD');
        $category = $this->category($owner, 'income');

        $this->expectException(ValidationException::class);
        app(FinanceRecurringOperationService::class)->create($owner, [
            'name' => 'Invalid', 'direction' => 'income', 'account_id' => $account->id,
            'category_id' => $category->id, 'amount' => '1', 'mandatory' => true,
            'starts_on' => '2026-08-01', 'ends_on' => null, 'interval_months' => 1,
            'month_days' => [1], 'reminder_time' => null,
        ]);
    }

    public function test_edit_preserves_factored_and_moved_snapshots_but_updates_other_future_rows(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $operation = $this->recurringOperation($owner, ['month_days' => [14, 15, 16]]);
        app(RecurrenceMaterializer::class)->materialize($operation->recurringRule, '2026-08-13');
        $occurrences = $operation->recurringRule->occurrences()->with('financeDetail')->orderBy('occurrence_date')->get();
        $occurrences[0]->update(['rescheduled_to' => '2026-08-20']);
        FinanceOccurrenceFact::factory()->skipped()->create([
            'user_id' => $owner->id, 'planned_occurrence_id' => $occurrences[1]->id,
        ]);

        app(FinanceRecurringOperationService::class)->update($operation, $owner, ['amount' => '250.0000']);

        $this->assertSame('100.0000', $occurrences[0]->financeDetail->fresh()->amount);
        $this->assertSame('100.0000', $occurrences[1]->financeDetail->fresh()->amount);
        $this->assertSame('250.0000', $occurrences[2]->financeDetail->fresh()->amount);
    }
}
