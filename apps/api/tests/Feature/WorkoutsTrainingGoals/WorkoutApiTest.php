<?php

namespace Tests\Feature\WorkoutsTrainingGoals;

use App\Models\Exercise;

class WorkoutApiTest extends WorkoutTestCase
{
    /** @return array<string, mixed> */
    private function programPayload(array $overrides = []): array
    {
        return [
            'name' => 'Strength A',
            'description' => 'Main lifts',
            'workout_type' => 'strength',
            'intensity' => 'moderate',
            'planned_duration_seconds' => 3600,
            'schedule_type' => 'daily',
            'weekdays' => [],
            'preferred_time' => '18:00',
            'starts_on' => self::TODAY,
            'ends_on' => null,
            ...$overrides,
        ];
    }

    public function test_all_fifteen_operations_require_authentication(): void
    {
        foreach ([
            ['getJson', '/api/exercises'],
            ['postJson', '/api/exercises', ['name' => 'Press', 'muscle_group' => 'chest', 'exercise_type' => 'strength']],
            ['patchJson', '/api/exercises/1', ['name' => 'Press']],
            ['getJson', '/api/workout-programs?date='.self::TODAY],
            ['postJson', '/api/workout-programs', $this->programPayload()],
            ['patchJson', '/api/workout-programs/1', ['name' => 'Edited']],
            ['putJson', '/api/workout-programs/1/exercises', ['exercises' => []]],
            ['putJson', '/api/workout-programs/1/sessions/'.self::TODAY, ['outcome' => 'skipped']],
            ['getJson', '/api/workouts?from='.self::TODAY.'&to='.self::TODAY],
            ['postJson', '/api/workouts', ['name' => 'Manual', 'workout_type' => 'sport', 'performed_on' => self::TODAY]],
            ['patchJson', '/api/workouts/1', ['note' => 'Edited']],
            ['deleteJson', '/api/workouts/1'],
            ['getJson', '/api/training/goals'],
            ['postJson', '/api/training/goals', ['name' => 'Run 5K', 'kind' => 'distance', 'activity' => 'running', 'target_value' => 5000]],
            ['patchJson', '/api/training/goals/1', ['target_value' => 10000]],
        ] as $case) {
            [$method, $uri] = $case;
            $payload = $case[2] ?? null;
            $response = isset($payload) ? $this->{$method}($uri, $payload) : $this->{$method}($uri);
            $response->assertUnauthorized();
        }
    }

    public function test_catalogue_and_strength_program_lifecycle_are_strict_and_owner_scoped(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $foreign = $this->createCustomExercise($other);
        $this->actingAs($owner);

        $this->getJson('/api/exercises?state=active')
            ->assertOk()
            ->assertJsonCount(6, 'data')
            ->assertJsonPath('data.0.is_builtin', true)
            ->assertJsonStructure(['data' => [['id', 'system_key', 'display_key', 'is_builtin']], 'options']);

        $exerciseId = $this->postJson('/api/exercises', [
            'name' => 'Landmine press', 'muscle_group' => 'shoulders',
            'equipment' => 'barbell', 'exercise_type' => 'strength',
        ])->assertCreated()->assertJsonPath('data.is_builtin', false)->json('data.id');

        $programId = $this->postJson('/api/workout-programs', $this->programPayload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Strength A')
            ->assertJsonPath('data.recurring_rule.slot_time', '18:00')
            ->assertJsonPath('data.selected_date', self::TODAY)
            ->json('data.id');

        $this->putJson("/api/workout-programs/{$programId}/exercises", ['exercises' => [[
            'exercise_id' => $exerciseId, 'sort_order' => 0, 'target_sets' => 3,
            'target_reps' => 5, 'starting_weight_kg' => 40, 'increment_kg' => 2.5,
            'successes_required' => 2,
        ]]])->assertOk()
            ->assertJsonPath('data.exercises.0.exercise.id', $exerciseId)
            ->assertJsonPath('data.exercises.0.progression.next_weight_kg', '40.000');

        $this->patchJson("/api/workout-programs/{$programId}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->patchJson("/api/exercises/{$exerciseId}", ['is_archived' => true])
            ->assertOk()->assertJsonPath('data.is_archived', true);
        $this->getJson('/api/exercises?state=archived')
            ->assertOk()->assertJsonPath('data.0.id', $exerciseId);

        $this->patchJson('/api/exercises/'.$foreign->id, ['name' => 'Stolen'])->assertNotFound();
        $this->putJson("/api/workout-programs/{$programId}/exercises", ['exercises' => [[
            'exercise_id' => $foreign->id, 'sort_order' => 0, 'target_sets' => 3,
            'target_reps' => 5, 'starting_weight_kg' => 40, 'increment_kg' => 2.5,
            'successes_required' => 2,
        ]]])->assertUnprocessable()->assertJsonValidationErrors('exercises.0.exercise_id');
        $this->patchJson("/api/workout-programs/{$programId}", ['workout_type' => 'cardio'])
            ->assertUnprocessable()->assertJsonValidationErrors('request');
    }

    public function test_planned_manual_history_and_correction_contracts_keep_stable_identity(): void
    {
        $owner = $this->createUser();
        $program = $this->createProgram($owner);
        $this->addPrescription($program);
        $this->actingAs($owner);

        $first = $this->putJson("/api/workout-programs/{$program->id}/sessions/".self::TODAY, $this->strengthPayload())
            ->assertOk()
            ->assertJsonPath('data.workout_program_id', $program->id)
            ->assertJsonPath('data.outcome', 'completed')
            ->assertJsonPath('data.strength.exercises.0.sets.0.weight_kg', '50.000');
        $sessionId = $first->json('data.id');

        $corrected = $this->strengthPayload(['note' => 'Corrected']);
        $corrected['strength']['exercises'][0]['sets'] = [[
            'set_order' => 0, 'weight_kg' => 52.5, 'reps' => 5, 'rest_seconds' => 90,
        ]];
        $this->putJson("/api/workout-programs/{$program->id}/sessions/".self::TODAY, $corrected)
            ->assertOk()->assertJsonPath('data.id', $sessionId)
            ->assertJsonPath('data.note', 'Corrected');

        $manualId = $this->postJson('/api/workouts', [
            'name' => 'Easy 5K', 'workout_type' => 'cardio', 'performed_on' => self::TODAY,
            'started_time' => '07:00', 'duration_seconds' => 1800,
            'endurance' => ['activity' => 'running', 'run_type' => 'easy', 'distance_m' => 5000],
        ])->assertCreated()->assertJsonPath('data.endurance.pace_seconds_per_km', 360)->json('data.id');

        $this->getJson('/api/workouts?from='.self::TODAY.'&to='.self::TODAY)
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('summary.planned', 1)
            ->assertJsonPath('summary.unplanned', 1)
            ->assertJsonPath('records.paces.0.best_pace_seconds_per_km', 360);

        $this->patchJson("/api/workouts/{$manualId}", ['duration_seconds' => 2000])
            ->assertOk()->assertJsonPath('data.duration_seconds', 2000);
        $this->deleteJson("/api/workouts/{$sessionId}")->assertNoContent();
        $this->assertSame('planned', $this->occurrenceOn($program)->fresh()->status);

        $this->postJson('/api/workouts', [
            'name' => 'Mixed', 'workout_type' => 'cardio', 'performed_on' => self::TODAY,
            'strength' => ['mode' => 'simple', 'exercises' => []],
            'endurance' => ['activity' => 'running', 'distance_m' => 5000],
        ])->assertUnprocessable()->assertJsonValidationErrors('strength.exercises');
    }

    public function test_training_goal_api_derives_progress_and_preserves_shared_goal_lifecycle(): void
    {
        $owner = $this->createUser();
        $exercise = $this->builtInExercise();
        $this->actingAs($owner);

        $goalId = $this->postJson('/api/training/goals', [
            'name' => 'Squat 100 kg', 'description' => null, 'target_date' => null,
            'kind' => 'strength', 'exercise_id' => $exercise->id, 'activity' => null,
            'workout_program_id' => null, 'target_value' => 100,
        ])->assertCreated()
            ->assertJsonPath('data.type', 'training')
            ->assertJsonPath('data.training.starting_value', '0.000')
            ->assertJsonPath('data.training.current_value', null)
            ->json('data.id');

        $this->postJson('/api/workouts', [
            'name' => 'Squats', 'workout_type' => 'strength', 'performed_on' => self::TODAY,
            'strength' => ['mode' => 'simple', 'exercises' => [[
                'exercise_id' => $exercise->id, 'sort_order' => 0,
                'simple_weight_kg' => 50, 'simple_reps' => 5, 'sets' => [],
            ]],
            ],
        ])->assertCreated();

        $this->getJson('/api/training/goals')
            ->assertOk()->assertJsonPath('data.0.training.current_value', '50.000')
            ->assertJsonPath('data.0.training.progress', 0.5);
        $this->patchJson("/api/training/goals/{$goalId}", [
            'target_value' => 120, 'status' => 'completed', 'is_archived' => true,
        ])->assertOk()->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.training.target_value', '120.000');
        $this->patchJson("/api/training/goals/{$goalId}", [
            'kind' => 'distance', 'starting_value' => 10,
        ])->assertUnprocessable()->assertJsonValidationErrors('request');

        $this->postJson('/api/exercises', [
            'name' => 'Unexpected', 'muscle_group' => 'legs',
            'exercise_type' => Exercise::TYPE_STRENGTH, 'future_field' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('request');
    }
}
