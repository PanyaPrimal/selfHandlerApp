<?php

namespace Tests\Unit\WorkoutsTrainingGoals;

use App\Models\Goal;
use App\Services\Planner\TrainingGoalSource;
use App\Services\TrainingGoalProgressService;
use App\Services\TrainingGoalService;
use App\Services\WorkoutSessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\WorkoutsTrainingGoals\WorkoutTestCase;

class TrainingGoalProgressServiceTest extends WorkoutTestCase
{
    public function test_strength_goal_snapshots_current_or_zero_and_progresses_from_matching_history(): void
    {
        $owner = $this->createUser();
        $exercise = $this->builtInExercise();
        $service = app(TrainingGoalService::class);
        $goal = $service->create($owner, [
            'name' => 'Squat 100 kg', 'description' => null, 'target_date' => null,
            'kind' => 'strength', 'exercise_id' => $exercise->id, 'activity' => null,
            'workout_program_id' => null, 'target_value' => 100,
        ]);

        $initial = app(TrainingGoalProgressService::class)->describe($goal);
        $this->assertSame('0.000', $initial['starting_value']);
        $this->assertNull($initial['current_value']);
        $this->assertNull($initial['progress']);

        app(WorkoutSessionService::class)->createManual($owner, [
            'name' => 'Squats', 'workout_type' => 'strength', 'performed_on' => self::TODAY,
            'strength' => ['mode' => 'simple', 'exercises' => [[
                'exercise_id' => $exercise->id, 'sort_order' => 0,
                'simple_weight_kg' => 50, 'simple_reps' => 5, 'sets' => [],
            ]]],
        ]);
        $after = app(TrainingGoalProgressService::class)->describe($goal->fresh('trainingDetail'));

        $this->assertSame('50.000', $after['current_value']);
        $this->assertEqualsWithDelta(0.5, $after['progress'], 0.0001);
    }

    public function test_distance_and_race_use_matching_activity_and_race_is_one_read_only_planner_event(): void
    {
        $owner = $this->createUser();
        $goal = app(TrainingGoalService::class)->create($owner, [
            'name' => 'Autumn 10K', 'description' => null, 'target_date' => '2026-10-10',
            'kind' => 'race', 'exercise_id' => null, 'activity' => 'running',
            'workout_program_id' => null, 'target_value' => 10000,
        ]);
        app(WorkoutSessionService::class)->createManual($owner, [
            'name' => 'Long run', 'workout_type' => 'cardio', 'performed_on' => self::TODAY,
            'duration_seconds' => 3600,
            'endurance' => ['activity' => 'running', 'run_type' => 'long', 'distance_m' => 8000],
        ]);
        app(WorkoutSessionService::class)->createManual($owner, [
            'name' => 'Ride', 'workout_type' => 'cardio', 'performed_on' => self::TODAY,
            'duration_seconds' => 3600,
            'endurance' => ['activity' => 'cycling', 'run_type' => null, 'distance_m' => 20000],
        ]);

        $progress = app(TrainingGoalProgressService::class)->describe($goal->fresh('trainingDetail'));
        $events = app(TrainingGoalSource::class)->entriesFor($owner, '2026-10-10');

        $this->assertSame('8000.000', $progress['current_value']);
        $this->assertEqualsWithDelta(0.8, $progress['progress'], 0.0001);
        $this->assertCount(1, $events);
        $this->assertSame('training_goal', $events[0]->source);
        $this->assertSame([], $events[0]->actions);
        $this->assertSame('/workouts?goal='.$goal->id, $events[0]->meta['action_url']);
        $this->assertSame([], app(TrainingGoalSource::class)->entriesFor($owner, self::TODAY));
    }

    public function test_consistency_counts_distinct_completed_sessions_in_trailing_seven_local_dates_and_scope(): void
    {
        $owner = $this->createUser(timezone: 'Europe/Kyiv');
        $program = $this->createProgram($owner, [], ['starts_on' => '2026-08-01']);
        $this->addPrescription($program);
        $goal = app(TrainingGoalService::class)->create($owner, [
            'name' => 'Three sessions weekly', 'description' => null, 'target_date' => null,
            'kind' => 'consistency', 'exercise_id' => null, 'activity' => null,
            'workout_program_id' => $program->id, 'target_value' => 3,
        ]);
        $this->createPlannedSession($program, $owner, '2026-08-11');
        $this->createPlannedSession($program, $owner, '2026-08-12');
        $this->createPlannedSession($program, $owner, self::TODAY, [
            'outcome' => 'skipped', 'note' => null,
        ]);
        app(WorkoutSessionService::class)->createManual($owner, [
            'name' => 'Unscoped run', 'workout_type' => 'cardio', 'performed_on' => self::TODAY,
            'duration_seconds' => 1200, 'endurance' => ['activity' => 'running', 'distance_m' => 3000],
        ]);

        $progress = app(TrainingGoalProgressService::class)->describe($goal->fresh('trainingDetail'));

        $this->assertSame('2.000', $progress['current_value']);
        $this->assertEqualsWithDelta(0.6667, $progress['progress'], 0.0001);
    }

    public function test_kind_scope_and_start_are_immutable_while_target_and_lifecycle_use_shared_goal(): void
    {
        $owner = $this->createUser();
        $goal = app(TrainingGoalService::class)->create($owner, [
            'name' => 'Run 5K', 'kind' => 'distance', 'activity' => 'running',
            'exercise_id' => null, 'workout_program_id' => null, 'target_date' => null,
            'target_value' => 5000,
        ]);

        try {
            app(TrainingGoalService::class)->update($goal, $owner, [
                'kind' => 'consistency', 'starting_value' => 2,
            ]);
            $this->fail('Immutable fields must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('kind', $exception->errors());
            $this->assertArrayHasKey('starting_value', $exception->errors());
        }

        $updated = app(TrainingGoalService::class)->update($goal, $owner, [
            'target_value' => 10000, 'status' => 'completed', 'is_archived' => true,
        ]);

        $this->assertSame(Goal::TYPE_TRAINING, $updated->type);
        $this->assertSame('completed', $updated->status);
        $this->assertNotNull($updated->completed_at);
        $this->assertTrue($updated->is_archived);
        $this->assertSame('0.000', $updated->trainingDetail->starting_value);
        $this->assertSame('10000.000', $updated->trainingDetail->target_value);
    }

    public function test_progress_loading_is_query_bounded_for_multiple_goals(): void
    {
        $owner = $this->createUser();
        foreach (range(1, 5) as $index) {
            app(TrainingGoalService::class)->create($owner, [
                'name' => "Run {$index}", 'kind' => 'distance', 'activity' => 'running',
                'exercise_id' => null, 'workout_program_id' => null, 'target_date' => null,
                'target_value' => 5000 + $index,
            ]);
        }
        $goals = Goal::query()->ownedBy($owner)->where('type', Goal::TYPE_TRAINING)
            ->with(['trainingDetail.exercise', 'trainingDetail.program', 'user.profile'])->get();
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(TrainingGoalProgressService::class)->describeMany($goals);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(8, $queries);
    }
}
