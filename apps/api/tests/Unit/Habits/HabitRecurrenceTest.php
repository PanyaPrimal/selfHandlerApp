<?php

namespace Tests\Unit\Habits;

use App\Models\HabitLog;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Services\OccurrenceFactSynchronizer;
use App\Services\RecurrenceMaterializer;
use Tests\Feature\Habits\HabitTestCase;

class HabitRecurrenceTest extends HabitTestCase
{
    public function test_habit_is_a_second_rule_owner_without_numeric_id_collision(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $habit = $this->createHabit($owner, ['preferred_time' => '08:30']);

        $this->assertSame($routine->id, $habit->id, 'Fixture proves ids collide across owner tables.');
        $this->assertSame(RecurringRule::OWNER_HABIT, $habit->recurringRule->owner_type);
        $this->assertSame('08:30', substr((string) $habit->recurringRule->slot_time, 0, 5));

        $routine->update(['is_active' => false]);
        app(RecurrenceMaterializer::class)->materializeForUser($owner, self::TODAY);

        $this->assertSame(0, PlannedOccurrence::query()
            ->where('recurring_rule_id', $routine->recurringRule->id)->count());
        $this->assertGreaterThan(0, PlannedOccurrence::query()
            ->where('recurring_rule_id', $habit->recurringRule->id)->count());
    }

    public function test_repeated_materialization_is_idempotent_for_habit_rules(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner, [], ['MO', 'WE', 'FR']);
        $materializer = app(RecurrenceMaterializer::class);
        $before = $habit->recurringRule->occurrences()->count();

        $materializer->materialize($habit->recurringRule, self::TODAY, true);
        $materializer->materialize($habit->recurringRule, self::TODAY, true);

        $this->assertSame($before, $habit->recurringRule->occurrences()->count());
    }

    public function test_fact_linked_and_rescheduled_habit_occurrences_survive_rule_edits(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner);
        $factOccurrence = $this->occurrenceOn($habit, self::TODAY);
        $movedOccurrence = $this->occurrenceOn($habit, '2026-08-14');
        $log = HabitLog::create([
            'user_id' => $owner->id,
            'habit_id' => $habit->id,
            'log_date' => self::TODAY,
            'outcome' => HabitLog::OUTCOME_DONE,
            'occurred_at' => now(),
        ]);
        $factOccurrence->update(['habit_log_id' => $log->id, 'status' => PlannedOccurrence::STATUS_DONE]);
        $movedOccurrence->update(['rescheduled_to' => '2026-08-20']);

        $habit->recurringRule->update(['frequency' => RecurringRule::FREQUENCY_WEEKLY]);
        $habit->recurringRule->syncWeekdays(['MO']);
        app(RecurrenceMaterializer::class)->materialize($habit->recurringRule->fresh('ruleWeekdays'), self::TODAY, true);

        $this->assertNotNull($factOccurrence->fresh());
        $this->assertNotNull($movedOccurrence->fresh());
    }

    public function test_reconcile_rebuilds_routine_and_habit_fact_mirrors(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner);
        $log = $this->createLog($habit, $owner, self::TODAY, [
            'outcome' => HabitLog::OUTCOME_DONE,
            'occurred_time' => '08:15',
        ]);
        $occurrence = $this->occurrenceOn($habit);
        $occurrence->forceFill([
            'habit_log_id' => null,
            'status' => PlannedOccurrence::STATUS_PLANNED,
        ])->save();

        app(OccurrenceFactSynchronizer::class)->reconcile($owner);

        $this->assertSame($log->id, $occurrence->fresh()->habit_log_id);
        $this->assertSame(PlannedOccurrence::STATUS_DONE, $occurrence->fresh()->status);
    }
}
