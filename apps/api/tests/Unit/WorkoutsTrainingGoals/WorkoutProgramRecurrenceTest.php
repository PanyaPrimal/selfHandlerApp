<?php

namespace Tests\Unit\WorkoutsTrainingGoals;

use App\Models\Habit;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\Routine;
use App\Models\SleepPlan;
use App\Services\HabitRecurrence;
use App\Services\OccurrenceFactSynchronizer;
use App\Services\RecurrenceMaterializer;
use App\Services\RoutineRecurrence;
use App\Services\SleepPlanRecurrence;
use Tests\Feature\WorkoutsTrainingGoals\WorkoutTestCase;

class WorkoutProgramRecurrenceTest extends WorkoutTestCase
{
    public function test_workout_is_fourth_owner_without_numeric_collision_and_global_materialization_is_idempotent(): void
    {
        $owner = $this->createUser();
        $routine = Routine::create(['user_id' => $owner->id, 'name' => 'Routine']);
        app(RoutineRecurrence::class)->apply($routine, $owner, ['schedule_type' => 'daily'], []);
        $habit = Habit::create([
            'user_id' => $owner->id,
            'name' => 'Habit',
            'kind' => Habit::KIND_HABIT,
            'mode' => Habit::MODE_YES_NO,
        ]);
        app(HabitRecurrence::class)->apply($habit, $owner, ['schedule_type' => 'daily'], []);
        $sleep = SleepPlan::create(['user_id' => $owner->id, 'name' => 'Sleep', 'planned_wake_time' => '07:00']);
        app(SleepPlanRecurrence::class)->apply($sleep, $owner, [
            'schedule_type' => 'daily', 'planned_bed_time' => '23:00',
        ], []);
        $program = $this->createProgram($owner, [], ['preferred_time' => '18:00']);

        $this->assertSame($routine->id, $program->id);
        $this->assertSame(RecurringRule::OWNER_WORKOUT_PROGRAM, $program->recurringRule->owner_type);
        $materializer = app(RecurrenceMaterializer::class);
        $materializer->materializeForUser($owner, self::TODAY);
        $count = $program->recurringRule->occurrences()->count();
        $materializer->materializeForUser($owner, self::TODAY);

        $this->assertGreaterThan(0, $count);
        $this->assertSame($count, $program->recurringRule->occurrences()->count());
        $this->assertSame('18:00', substr((string) $this->occurrenceOn($program)->occurrence_time, 0, 5));
    }

    public function test_fact_and_reschedule_survive_schedule_and_lifecycle_changes(): void
    {
        $owner = $this->createUser();
        $program = $this->createProgram($owner);
        $this->addPrescription($program);
        $fact = $this->createPlannedSession($program, $owner);
        $factOccurrence = $this->occurrenceOn($program);
        $moved = $this->occurrenceOn($program, self::TOMORROW);
        $moved->update(['rescheduled_to' => '2026-08-20']);

        $program->update(['is_active' => false]);
        app(RecurrenceMaterializer::class)->materialize($program->recurringRule, self::TODAY, false);

        $this->assertSame($fact->id, $factOccurrence->fresh()->workout_session_id);
        $this->assertNotNull($moved->fresh());
        $this->assertSame('2026-08-20', $moved->fresh()->rescheduled_to->format('Y-m-d'));
    }

    public function test_reconcile_repairs_workout_fact_link_and_status_without_touching_other_owners(): void
    {
        $owner = $this->createUser();
        $program = $this->createProgram($owner);
        $this->addPrescription($program);
        $session = $this->createPlannedSession($program, $owner);
        $occurrence = $this->occurrenceOn($program);
        $occurrence->forceFill([
            'workout_session_id' => null,
            'status' => PlannedOccurrence::STATUS_PLANNED,
        ])->save();

        app(OccurrenceFactSynchronizer::class)->reconcile($owner);

        $this->assertSame($session->id, $occurrence->fresh()->workout_session_id);
        $this->assertSame(PlannedOccurrence::STATUS_DONE, $occurrence->fresh()->status);
    }
}
