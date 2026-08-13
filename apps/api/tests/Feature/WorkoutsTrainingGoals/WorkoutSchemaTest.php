<?php

namespace Tests\Feature\WorkoutsTrainingGoals;

use App\Models\Exercise;
use App\Models\Goal;
use App\Models\TrainingGoalDetail;
use App\Models\WorkoutProgramExercise;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

class WorkoutSchemaTest extends WorkoutTestCase
{
    public function test_all_additive_tables_columns_builtins_and_fact_link_exist(): void
    {
        foreach ([
            'exercises' => ['id', 'user_id', 'system_key', 'name', 'muscle_group', 'equipment', 'exercise_type', 'is_archived', 'archived_at'],
            'workout_programs' => ['id', 'user_id', 'name', 'description', 'workout_type', 'intensity', 'planned_duration_seconds', 'is_active', 'is_archived', 'archived_at'],
            'workout_program_exercises' => ['id', 'user_id', 'workout_program_id', 'exercise_id', 'sort_order', 'target_sets', 'target_reps', 'starting_weight_kg', 'increment_kg', 'successes_required'],
            'workout_program_endurance_details' => ['id', 'user_id', 'workout_program_id', 'activity', 'run_type', 'target_distance_m'],
            'workout_program_timed_details' => ['id', 'user_id', 'workout_program_id', 'activity_name'],
            'workout_sessions' => ['id', 'user_id', 'workout_program_id', 'name', 'workout_type', 'outcome', 'performed_on', 'started_at', 'duration_seconds', 'note'],
            'workout_strength_details' => ['id', 'user_id', 'workout_session_id', 'mode'],
            'workout_endurance_details' => ['id', 'user_id', 'workout_session_id', 'activity', 'run_type', 'distance_m', 'average_heart_rate', 'energy_kcal'],
            'workout_timed_details' => ['id', 'user_id', 'workout_session_id', 'activity_name'],
            'workout_session_exercises' => ['id', 'user_id', 'workout_session_id', 'exercise_id', 'sort_order', 'simple_weight_kg', 'simple_reps', 'note'],
            'workout_sets' => ['id', 'user_id', 'workout_session_exercise_id', 'set_order', 'weight_kg', 'reps', 'rest_seconds'],
            'training_goal_details' => ['id', 'user_id', 'goal_id', 'kind', 'exercise_id', 'activity', 'workout_program_id', 'starting_value', 'target_value'],
        ] as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertTrue(Schema::hasColumns($table, $columns), $table);
        }

        $this->assertTrue(Schema::hasColumn('planned_occurrences', 'workout_session_id'));
        $this->assertSame(6, Exercise::query()->whereNotNull('system_key')->count());
        $this->assertSame(
            ['bench_press', 'deadlift', 'overhead_press', 'pull_up', 'row', 'squat'],
            Exercise::query()->whereNotNull('system_key')->orderBy('system_key')->pluck('system_key')->all(),
        );
    }

    public function test_schema_uniques_protect_catalogue_program_order_session_and_subtype_identity(): void
    {
        $owner = $this->createUser();
        $program = $this->createProgram($owner);
        $exercise = $this->builtInExercise();
        $this->createCustomExercise($owner, ['name' => 'Duplicate custom']);
        WorkoutProgramExercise::create([
            'user_id' => $owner->id, 'workout_program_id' => $program->id,
            'exercise_id' => $exercise->id, 'sort_order' => 0, 'target_sets' => 3,
            'target_reps' => 5, 'starting_weight_kg' => 50, 'increment_kg' => 2.5,
            'successes_required' => 2,
        ]);

        $attempts = [
            fn () => Exercise::create([
                'user_id' => $owner->id, 'name' => 'Duplicate custom', 'muscle_group' => 'legs',
                'exercise_type' => Exercise::TYPE_STRENGTH,
            ]),
            fn () => WorkoutProgramExercise::create([
                'user_id' => $owner->id, 'workout_program_id' => $program->id,
                'exercise_id' => $this->builtInExercise('bench_press')->id, 'sort_order' => 0,
                'target_sets' => 3, 'target_reps' => 5, 'starting_weight_kg' => 40,
                'increment_kg' => 2.5, 'successes_required' => 2,
            ]),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('Expected a unique constraint violation.');
            } catch (UniqueConstraintViolationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_account_deletion_cascades_private_rows_but_not_builtins(): void
    {
        $owner = $this->createUser();
        $custom = $this->createCustomExercise($owner);
        $program = $this->createProgram($owner);
        $this->addPrescription($program, $custom);
        $payload = $this->strengthPayload();
        $payload['strength']['exercises'][0]['exercise_id'] = $custom->id;
        $session = $this->createPlannedSession($program, $owner, data: $payload);
        $goal = Goal::create(['user_id' => $owner->id, 'name' => 'Squat', 'type' => Goal::TYPE_TRAINING]);
        TrainingGoalDetail::create([
            'user_id' => $owner->id, 'goal_id' => $goal->id, 'kind' => 'strength',
            'exercise_id' => $custom->id, 'target_value' => 100,
        ]);

        $owner->delete();

        $this->assertSame(6, Exercise::query()->count());
        $this->assertDatabaseMissing('workout_programs', ['id' => $program->id]);
        $this->assertDatabaseMissing('workout_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('training_goal_details', ['goal_id' => $goal->id]);
    }

    public function test_rollback_removes_only_feature_015_and_preserves_prior_rows(): void
    {
        $owner = $this->createUser();
        $goal = Goal::create(['user_id' => $owner->id, 'name' => 'Existing goal']);
        $migration = require database_path('migrations/2026_08_13_200000_create_workouts_and_training_goals.php');

        $migration->down();

        $this->assertFalse(Schema::hasTable('workout_programs'));
        $this->assertFalse(Schema::hasTable('training_goal_details'));
        $this->assertFalse(Schema::hasColumn('planned_occurrences', 'workout_session_id'));
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertDatabaseHas('goals', ['id' => $goal->id]);

        $migration->up();

        $this->assertTrue(Schema::hasTable('workout_programs'));
        $this->assertTrue(Schema::hasColumn('planned_occurrences', 'workout_session_id'));
    }

    public function test_every_new_index_name_is_mysql_safe(): void
    {
        foreach ([
            'exercises', 'workout_programs', 'workout_program_exercises',
            'workout_program_endurance_details', 'workout_program_timed_details', 'workout_sessions',
            'workout_strength_details', 'workout_endurance_details', 'workout_timed_details',
            'workout_session_exercises', 'workout_sets', 'training_goal_details', 'planned_occurrences',
        ] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), $index['name']);
            }
        }
    }
}
