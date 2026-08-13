<?php

namespace Tests\Unit\SleepRoutineTemplates;

use App\Models\Routine;
use App\Models\RoutineActivity;
use App\Models\RoutineActivityLog;
use App\Models\RoutineDaySelection;
use App\Models\SleepLog;
use App\Models\SleepOccurrenceDetail;
use RuntimeException;
use Tests\Feature\SleepRoutineTemplates\SleepRoutineTestCase;

class ModelTest extends SleepRoutineTestCase
{
    public function test_sleep_models_expose_owned_relationships_and_casts(): void
    {
        $owner = $this->createUser();
        $plan = $this->createSleepPlan($owner);
        $occurrence = $this->sleepOccurrenceOn($plan);
        $log = SleepLog::create([
            'user_id' => $owner->id,
            'sleep_plan_id' => $plan->id,
            'sleep_date' => self::TODAY,
            'actual_bed_at' => self::TODAY.' 23:00:00',
            'actual_wake_at' => self::TOMORROW.' 07:00:00',
            'quality' => 8,
        ]);

        $this->assertTrue($plan->is_active);
        $this->assertFalse($plan->is_archived);
        $this->assertSame($plan->id, $plan->recurringRule->owner_id);
        $this->assertSame($plan->id, $log->sleepPlan->id);
        $this->assertSame(self::TODAY, $log->sleep_date->format('Y-m-d'));
        $this->assertSame($occurrence->id, $occurrence->sleepDetail->planned_occurrence_id);
        $this->assertSame('07:00', substr((string) $occurrence->sleepDetail->planned_wake_time, 0, 5));
    }

    public function test_routine_models_expose_activity_facts_selections_and_decimal_casts(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $activity = $this->createActivity($routine, ['progress_total' => '10.500']);
        $log = RoutineActivityLog::create([
            'user_id' => $owner->id,
            'routine_activity_id' => $activity->id,
            'log_date' => self::TODAY,
            'status' => RoutineActivityLog::STATUS_DONE,
            'progress_value' => '4.250',
            'completed_at' => now(),
        ]);
        $selection = RoutineDaySelection::create([
            'user_id' => $owner->id,
            'selection_date' => self::TODAY,
            'period' => Routine::DAY_PERIOD_MORNING,
            'routine_id' => $routine->id,
        ]);

        $this->assertSame('10.500', $activity->progress_total);
        $this->assertSame('4.250', $log->progress_value);
        $this->assertSame($routine->id, $activity->routine->id);
        $this->assertSame($activity->id, $log->activity->id);
        $this->assertSame($routine->id, $selection->routine->id);
        $this->assertCount(1, $routine->fresh('activities.logs')->activities);
    }

    public function test_every_nested_model_rejects_a_cross_owner_parent(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $plan = $this->createSleepPlan($owner);
        $routine = $this->createRoutine($owner);
        $activity = $this->createActivity($routine);
        $occurrence = $this->sleepOccurrenceOn($plan);

        $attempts = [
            fn () => SleepOccurrenceDetail::create([
                'user_id' => $other->id,
                'planned_occurrence_id' => $occurrence->id,
                'planned_wake_time' => '07:00',
            ]),
            fn () => SleepLog::create([
                'user_id' => $other->id,
                'sleep_plan_id' => $plan->id,
                'sleep_date' => self::TODAY,
                'actual_bed_at' => self::TODAY.' 23:00:00',
                'actual_wake_at' => self::TOMORROW.' 07:00:00',
                'quality' => 8,
            ]),
            fn () => RoutineActivity::create([
                'user_id' => $other->id,
                'routine_id' => $routine->id,
                'name' => 'Foreign',
                'sort_order' => 2,
            ]),
            fn () => RoutineActivityLog::create([
                'user_id' => $other->id,
                'routine_activity_id' => $activity->id,
                'log_date' => self::TODAY,
                'status' => RoutineActivityLog::STATUS_DONE,
            ]),
            fn () => RoutineDaySelection::create([
                'user_id' => $other->id,
                'selection_date' => self::TODAY,
                'period' => Routine::DAY_PERIOD_MORNING,
                'routine_id' => $routine->id,
            ]),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('Expected same-owner protection.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_user_id_is_immutable_on_every_new_model(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $models = [
            $this->createSleepPlan($owner),
            $this->sleepOccurrenceOn($this->createSleepPlan($other))->sleepDetail,
            $this->createActivity($this->createRoutine($owner)),
        ];

        foreach ($models as $model) {
            try {
                $model->update(['user_id' => $model->user_id === $owner->id ? $other->id : $owner->id]);
                $this->fail('Expected immutable ownership.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
