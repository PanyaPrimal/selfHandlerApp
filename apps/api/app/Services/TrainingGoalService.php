<?php

namespace App\Services;

use App\Models\Goal;
use App\Models\TrainingGoalDetail;
use App\Models\User;
use App\Models\WorkoutProgram;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TrainingGoalService
{
    public function __construct(
        private readonly ExerciseCatalogueService $catalogue,
        private readonly TrainingGoalProgressService $progress,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): Goal
    {
        $this->validateScope($user, $data);
        [$current] = $this->progress->currentFor(
            $user, $data['kind'], $data['exercise_id'] ?? null,
            $data['activity'] ?? null, $data['workout_program_id'] ?? null,
        );

        return DB::transaction(function () use ($user, $data, $current): Goal {
            $goal = Goal::create([
                'user_id' => $user->id, 'name' => $data['name'],
                'description' => $data['description'] ?? null, 'type' => Goal::TYPE_TRAINING,
                'target_date' => $data['target_date'] ?? null,
            ]);
            TrainingGoalDetail::create([
                'user_id' => $user->id, 'goal_id' => $goal->id, 'kind' => $data['kind'],
                'exercise_id' => $data['exercise_id'] ?? null, 'activity' => $data['activity'] ?? null,
                'workout_program_id' => $data['workout_program_id'] ?? null,
                'starting_value' => $current ?? 0, 'target_value' => $data['target_value'],
            ]);

            return $goal->fresh(['trainingDetail.exercise', 'trainingDetail.program', 'user.profile']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Goal $goal, User $user, array $data): Goal
    {
        if ((int) $goal->user_id !== (int) $user->id || $goal->type !== Goal::TYPE_TRAINING) {
            throw new NotFoundHttpException;
        }
        $immutable = array_intersect(['kind', 'exercise_id', 'activity', 'workout_program_id', 'starting_value'], array_keys($data));
        if ($immutable !== []) {
            throw ValidationException::withMessages(array_fill_keys($immutable, __('messages.workout_goal_scope_immutable')));
        }

        return DB::transaction(function () use ($goal, $data): Goal {
            $detail = $goal->trainingDetail;
            if (array_key_exists('target_value', $data)) {
                $detail->update(['target_value' => $data['target_value']]);
            }
            $goal->applyLifecycle(array_intersect_key($data, array_flip([
                'name', 'description', 'target_date', 'status', 'is_archived',
            ])));
            $goal->save();

            return $goal->fresh(['trainingDetail.exercise', 'trainingDetail.program', 'user.profile']);
        });
    }

    /** @param array<string, mixed> $data */
    private function validateScope(User $user, array $data): void
    {
        $kind = $data['kind'] ?? null;
        if (! in_array($kind, TrainingGoalDetail::KINDS, true) || (float) ($data['target_value'] ?? 0) <= 0) {
            throw ValidationException::withMessages(['kind' => __('messages.workout_goal_invalid')]);
        }
        if ($kind === TrainingGoalDetail::KIND_STRENGTH) {
            $this->catalogue->assertAccessible((int) ($data['exercise_id'] ?? 0), $user);
            if (($data['activity'] ?? null) !== null) {
                throw ValidationException::withMessages(['activity' => __('messages.workout_goal_invalid')]);
            }
        } elseif (in_array($kind, [TrainingGoalDetail::KIND_DISTANCE, TrainingGoalDetail::KIND_RACE], true)) {
            if (blank($data['activity'] ?? null) || ($data['exercise_id'] ?? null) !== null) {
                throw ValidationException::withMessages(['activity' => __('messages.workout_goal_invalid')]);
            }
            if ($kind === TrainingGoalDetail::KIND_RACE
                && (($data['activity'] ?? null) !== 'running' || blank($data['target_date'] ?? null))) {
                throw ValidationException::withMessages(['target_date' => __('messages.workout_goal_invalid')]);
            }
        } elseif (($data['exercise_id'] ?? null) !== null || ($data['activity'] ?? null) !== null) {
            throw ValidationException::withMessages(['kind' => __('messages.workout_goal_invalid')]);
        }
        if (($data['workout_program_id'] ?? null) !== null && ! WorkoutProgram::query()
            ->ownedBy($user)->whereKey($data['workout_program_id'])->exists()) {
            throw ValidationException::withMessages(['workout_program_id' => __('messages.workout_goal_invalid')]);
        }
    }
}
