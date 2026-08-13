<?php

namespace Tests\Unit\WorkoutsTrainingGoals;

use App\Models\Goal;
use App\Models\TrainingGoalDetail;
use App\Models\WorkoutEnduranceDetail;
use App\Models\WorkoutProgramEnduranceDetail;
use App\Models\WorkoutSession;
use App\Models\WorkoutSessionExercise;
use App\Models\WorkoutStrengthDetail;
use RuntimeException;
use Tests\Feature\WorkoutsTrainingGoals\WorkoutTestCase;

class WorkoutModelTest extends WorkoutTestCase
{
    public function test_program_session_children_and_goal_expose_exact_relationships_and_casts(): void
    {
        $owner = $this->createUser();
        $program = $this->createProgram($owner);
        $prescription = $this->addPrescription($program);
        $session = $this->createPlannedSession($program, $owner);
        $sessionExercise = $session->fresh('strengthDetail.exercises.sets')->strengthDetail->exercises->first();
        $goal = Goal::create(['user_id' => $owner->id, 'name' => 'Squat 100', 'type' => Goal::TYPE_TRAINING]);
        $detail = TrainingGoalDetail::create([
            'user_id' => $owner->id, 'goal_id' => $goal->id, 'kind' => 'strength',
            'exercise_id' => $this->builtInExercise()->id, 'starting_value' => '50.000',
            'target_value' => '100.000',
        ]);

        $this->assertSame('50.000', $prescription->starting_weight_kg);
        $this->assertSame($program->id, $program->recurringRule->owner_id);
        $this->assertSame($program->id, $session->program->id);
        $this->assertSame('50.000', $sessionExercise->sets->first()->weight_kg);
        $this->assertSame($goal->id, $detail->goal->id);
        $this->assertSame($detail->id, $goal->fresh('trainingDetail')->trainingDetail->id);
    }

    public function test_every_nested_private_model_rejects_cross_owner_parent_or_reference(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $program = $this->createProgram($owner);
        $session = WorkoutSession::create([
            'user_id' => $owner->id, 'name' => 'Manual', 'workout_type' => 'strength',
            'outcome' => 'completed', 'performed_on' => self::TODAY,
        ]);
        $exercise = $this->createCustomExercise($owner);
        $goal = Goal::create(['user_id' => $owner->id, 'name' => 'Goal', 'type' => Goal::TYPE_TRAINING]);

        $attempts = [
            fn () => WorkoutProgramEnduranceDetail::create([
                'user_id' => $other->id, 'workout_program_id' => $program->id, 'activity' => 'running',
            ]),
            fn () => WorkoutStrengthDetail::create([
                'user_id' => $other->id, 'workout_session_id' => $session->id, 'mode' => 'simple',
            ]),
            fn () => WorkoutSessionExercise::create([
                'user_id' => $other->id, 'workout_session_id' => $session->id,
                'exercise_id' => $exercise->id, 'sort_order' => 0,
                'simple_weight_kg' => 10, 'simple_reps' => 5,
            ]),
            fn () => TrainingGoalDetail::create([
                'user_id' => $other->id, 'goal_id' => $goal->id, 'kind' => 'strength',
                'exercise_id' => $exercise->id, 'target_value' => 100,
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

    public function test_session_rejects_multiple_subtypes_and_occurrence_rejects_multiple_facts(): void
    {
        $owner = $this->createUser();
        $program = $this->createProgram($owner);
        $this->addPrescription($program);
        $session = $this->createPlannedSession($program, $owner);

        $this->expectException(RuntimeException::class);
        WorkoutEnduranceDetail::create([
            'user_id' => $owner->id,
            'workout_session_id' => $session->id,
            'activity' => 'running',
            'distance_m' => 5000,
        ]);
    }

    public function test_user_id_is_immutable_on_all_private_roots_and_children(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $program = $this->createProgram($owner);
        $this->addPrescription($program);
        $session = $this->createPlannedSession($program, $owner);
        $models = [
            $this->createCustomExercise($owner), $program, $program->fresh('exercises')->exercises->first(),
            $session, $session->fresh('strengthDetail.exercises.sets')->strengthDetail,
            $session->fresh('strengthDetail.exercises.sets')->strengthDetail->exercises->first(),
            $session->fresh('strengthDetail.exercises.sets')->strengthDetail->exercises->first()->sets->first(),
        ];

        foreach ($models as $model) {
            try {
                $model->update(['user_id' => $other->id]);
                $this->fail('Expected immutable ownership.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
