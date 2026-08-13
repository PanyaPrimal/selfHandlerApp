<?php

namespace Tests\Unit\Recurrence;

use App\Models\FinanceRecurringOperation;
use App\Models\RecurringRule;
use App\Services\RecurrenceMaterializer;
use App\Services\RecurringRuleExpander;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyRecurrenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_month_days_anchor_to_start_month_and_skip_short_months(): void
    {
        $operation = FinanceRecurringOperation::factory()->create();
        $rule = RecurringRule::factory()->create([
            'user_id' => $operation->user_id,
            'owner_type' => RecurringRule::OWNER_FINANCE_RECURRING_OPERATION,
            'owner_id' => $operation->id,
            'frequency' => RecurringRule::FREQUENCY_MONTHLY,
            'interval_count' => 2,
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-05-31',
        ]);
        $rule->syncMonthdays([5, 31]);

        $this->assertSame(
            ['2026-01-05', '2026-01-31', '2026-03-05', '2026-03-31', '2026-05-05', '2026-05-31'],
            app(RecurringRuleExpander::class)->datesBetween($rule->fresh(), '2026-01-01', '2026-05-31'),
        );
    }

    public function test_leap_day_and_implicit_ten_year_ceiling_are_deterministic(): void
    {
        $operation = FinanceRecurringOperation::factory()->create();
        $rule = RecurringRule::factory()->create([
            'user_id' => $operation->user_id,
            'owner_type' => RecurringRule::OWNER_FINANCE_RECURRING_OPERATION,
            'owner_id' => $operation->id,
            'frequency' => RecurringRule::FREQUENCY_MONTHLY,
            'starts_on' => '2024-01-01',
            'ends_on' => null,
        ]);
        $rule->syncMonthdays([29]);
        $expander = app(RecurringRuleExpander::class);

        $this->assertSame(['2024-02-29'], $expander->datesBetween($rule->fresh(), '2024-02-01', '2024-02-29'));
        $this->assertSame([], $expander->datesBetween($rule->fresh(), '2034-01-02', '2034-02-28'));
    }

    public function test_repeated_materialization_creates_stable_finance_occurrences_and_details_once(): void
    {
        $operation = FinanceRecurringOperation::factory()->create();
        $rule = $operation->recurringRule()->create([
            'user_id' => $operation->user_id,
            'owner_type' => RecurringRule::OWNER_FINANCE_RECURRING_OPERATION,
            'frequency' => RecurringRule::FREQUENCY_MONTHLY,
            'interval_count' => 1,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-31',
            'timezone' => 'Europe/Kyiv',
        ]);
        $rule->syncMonthdays([5, 15, 25]);
        $materializer = app(RecurrenceMaterializer::class);

        $materializer->materialize($rule->fresh(), '2026-08-01');
        $materializer->materialize($rule->fresh(), '2026-08-01');

        $this->assertDatabaseCount('planned_occurrences', 3);
        $this->assertDatabaseCount('finance_occurrence_details', 3);
    }
}
