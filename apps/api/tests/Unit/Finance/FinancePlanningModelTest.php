<?php

namespace Tests\Unit\Finance;

use App\Models\FinanceOccurrenceFact;
use App\Models\FinanceRecurringOperation;
use App\Models\RecurringRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class FinancePlanningModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_operation_rule_and_monthdays_have_one_owner(): void
    {
        $operation = FinanceRecurringOperation::factory()->create();
        $other = User::factory()->create();

        $this->expectException(RuntimeException::class);
        $operation->recurringRule()->create([
            'user_id' => $other->id,
            'owner_type' => RecurringRule::OWNER_FINANCE_RECURRING_OPERATION,
            'frequency' => RecurringRule::FREQUENCY_MONTHLY,
            'interval_count' => 1,
            'starts_on' => '2026-08-01',
            'timezone' => 'UTC',
        ]);
    }

    public function test_actual_occurrence_fact_is_append_only(): void
    {
        $fact = FinanceOccurrenceFact::factory()->actual()->create();

        try {
            $fact->update(['outcome' => 'skipped']);
            $this->fail('An actual Finance outcome must be immutable.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $this->expectException(RuntimeException::class);
        $fact->delete();
    }
}
