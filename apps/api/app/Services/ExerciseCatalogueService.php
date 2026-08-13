<?php

namespace App\Services;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ExerciseCatalogueService
{
    public function visible(User $user, string $state = 'active'): Collection
    {
        $query = Exercise::query()->visibleTo($user);
        if ($state === 'archived') {
            $query->whereNotNull('user_id')->where('is_archived', true);
        } elseif ($state === 'all') {
            // No lifecycle filter.
        } else {
            $query->where('is_archived', false);
        }

        return $query->orderByRaw('CASE WHEN system_key IS NULL THEN 1 ELSE 0 END')
            ->orderBy('name')->orderBy('id')->get();
    }

    /** @param array<string, mixed> $attributes */
    public function update(Exercise $exercise, User $user, array $attributes): Exercise
    {
        if ($exercise->system_key !== null) {
            throw ValidationException::withMessages(['exercise' => __('messages.workout_builtin_immutable')]);
        }
        if ((int) $exercise->user_id !== (int) $user->id) {
            throw new NotFoundHttpException;
        }

        $exercise->applyLifecycle($attributes);
        $exercise->save();

        return $exercise->fresh();
    }

    public function assertAccessible(int $exerciseId, User $user): Exercise
    {
        $exercise = Exercise::query()->find($exerciseId);
        if (! $exercise || ! $exercise->isAccessibleTo($user)) {
            throw ValidationException::withMessages([
                'exercise_id' => __('messages.workout_exercise_unavailable'),
            ]);
        }

        return $exercise;
    }
}
