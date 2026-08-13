<?php

namespace Tests\Unit\Habits;

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\PlannedOccurrence;
use App\Services\HabitStatisticsService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Habits\HabitTestCase;

class HabitStatisticsServiceTest extends HabitTestCase
{
    public function test_yes_no_statistics_use_scheduled_opportunities_and_ignore_open_today(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner, ['starts_on' => '2026-08-01']);

        $this->seedOpportunity($habit, $owner->id, '2026-08-07', HabitLog::OUTCOME_DONE);
        $this->seedOpportunity($habit, $owner->id, '2026-08-08', HabitLog::OUTCOME_DONE);
        $this->seedOpportunity($habit, $owner->id, '2026-08-09');
        $this->seedOpportunity($habit, $owner->id, '2026-08-10', HabitLog::OUTCOME_DONE);
        $this->seedOpportunity($habit, $owner->id, '2026-08-11', HabitLog::OUTCOME_NOT_DONE);
        $this->seedOpportunity($habit, $owner->id, '2026-08-12', HabitLog::OUTCOME_DONE);
        $this->seedOpportunity($habit, $owner->id, self::TODAY);

        $stats = $this->service()->calculate($habit, '2026-08-07', self::TODAY, self::TODAY);

        $this->assertSame(6, $stats['opportunities']);
        $this->assertSame(4, $stats['successes']);
        $this->assertEqualsWithDelta(66.667, $stats['completion_percentage'], 0.001);
        $this->assertSame(1, $stats['current_streak']);
        $this->assertSame(2, $stats['best_streak']);
        $this->assertNull($stats['numeric_total']);
    }

    public function test_numeric_target_controls_success_while_total_keeps_actual_values(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner, [
            'mode' => Habit::MODE_NUMERIC,
            'target_value' => 20,
            'unit' => 'pages',
        ]);
        $this->seedOpportunity($habit, $owner->id, '2026-08-11', HabitLog::OUTCOME_RECORDED, 10);
        $this->seedOpportunity($habit, $owner->id, '2026-08-12', HabitLog::OUTCOME_RECORDED, 25);
        $this->seedOpportunity($habit, $owner->id, self::TODAY, HabitLog::OUTCOME_RECORDED, 30);

        $stats = $this->service()->calculate($habit, '2026-08-11', self::TODAY, self::TODAY);

        $this->assertSame(3, $stats['opportunities']);
        $this->assertSame(2, $stats['successes']);
        $this->assertSame(2, $stats['current_streak']);
        $this->assertEqualsWithDelta(65.0, $stats['numeric_total'], 0.001);
    }

    public function test_abstinence_requires_protected_and_relapse_breaks_the_chain(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner, [
            'kind' => Habit::KIND_ANTI_HABIT,
            'mode' => Habit::MODE_ABSTINENCE,
        ]);
        $this->seedOpportunity($habit, $owner->id, '2026-08-09', HabitLog::OUTCOME_PROTECTED);
        $this->seedOpportunity($habit, $owner->id, '2026-08-10', HabitLog::OUTCOME_PROTECTED);
        $this->seedOpportunity($habit, $owner->id, '2026-08-11', HabitLog::OUTCOME_RELAPSE);
        $this->seedOpportunity($habit, $owner->id, '2026-08-12', HabitLog::OUTCOME_PROTECTED);
        $this->seedOpportunity($habit, $owner->id, self::TODAY, HabitLog::OUTCOME_PROTECTED);

        $stats = $this->service()->calculate($habit, '2026-08-09', self::TODAY, self::TODAY);

        $this->assertSame(5, $stats['opportunities']);
        $this->assertSame(4, $stats['successes']);
        $this->assertSame(2, $stats['current_streak']);
        $this->assertSame(2, $stats['best_streak']);
    }

    public function test_skipped_and_missing_ended_opportunities_fail_but_future_does_not(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner);
        $this->seedOpportunity($habit, $owner->id, '2026-08-11', HabitLog::OUTCOME_DONE);
        $this->seedOpportunity($habit, $owner->id, '2026-08-12', HabitLog::OUTCOME_SKIPPED);
        $this->seedOpportunity($habit, $owner->id, self::TODAY);
        $this->seedOpportunity($habit, $owner->id, '2026-08-14');

        $stats = $this->service()->calculate($habit, '2026-08-11', '2026-08-14', self::TODAY);

        $this->assertSame(2, $stats['opportunities']);
        $this->assertSame(1, $stats['successes']);
        $this->assertSame(0, $stats['current_streak']);
    }

    public function test_selected_range_is_inclusive_and_correction_recalculates_without_counters(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner);
        $this->seedOpportunity($habit, $owner->id, '2026-08-10', HabitLog::OUTCOME_DONE);
        $this->seedOpportunity($habit, $owner->id, '2026-08-11', HabitLog::OUTCOME_NOT_DONE);
        $this->seedOpportunity($habit, $owner->id, '2026-08-12', HabitLog::OUTCOME_DONE);

        $before = $this->service()->calculate($habit, '2026-08-11', '2026-08-12', self::TODAY);
        $habit->logs()->where('log_date', '2026-08-11')->update(['outcome' => HabitLog::OUTCOME_DONE]);
        $after = $this->service()->calculate($habit, '2026-08-11', '2026-08-12', self::TODAY);

        $this->assertSame([1, 2], [$before['successes'], $after['successes']]);
        $this->assertSame([1, 2], [$before['best_streak'], $after['best_streak']]);
    }

    public function test_calculation_has_a_fixed_query_budget(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner);

        foreach (range(1, 40) as $offset) {
            $date = now()->subDays($offset)->toDateString();
            $this->seedOpportunity($habit, $owner->id, $date, HabitLog::OUTCOME_DONE);
        }

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        try {
            $this->service()->calculate($habit, '2026-06-01', self::TODAY, self::TODAY);
            $count = count(DB::connection()->getQueryLog());
        } finally {
            DB::connection()->disableQueryLog();
        }

        $this->assertLessThanOrEqual(4, $count);
    }

    private function service(): HabitStatisticsService
    {
        return app(HabitStatisticsService::class);
    }

    private function seedOpportunity(
        Habit $habit,
        int $userId,
        string $date,
        ?string $outcome = null,
        ?float $value = null,
    ): PlannedOccurrence {
        $occurrence = PlannedOccurrence::query()->updateOrCreate([
            'recurring_rule_id' => $habit->recurringRule->id,
            'occurrence_date' => $date,
            'slot' => '',
        ], [
            'user_id' => $userId,
            'occurrence_time' => $habit->recurringRule->slot_time,
            'status' => PlannedOccurrence::STATUS_PLANNED,
            'materialized_at' => now(),
        ]);

        if ($outcome !== null) {
            $log = HabitLog::create([
                'user_id' => $userId,
                'habit_id' => $habit->id,
                'log_date' => $date,
                'outcome' => $outcome,
                'value' => $value,
                'occurred_at' => $outcome === HabitLog::OUTCOME_SKIPPED ? null : $date.' 08:00:00',
            ]);
            $occurrence->update([
                'habit_log_id' => $log->id,
                'status' => $outcome === HabitLog::OUTCOME_SKIPPED
                    ? PlannedOccurrence::STATUS_SKIPPED
                    : PlannedOccurrence::STATUS_DONE,
            ]);
        }

        return $occurrence;
    }
}
