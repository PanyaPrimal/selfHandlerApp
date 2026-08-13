<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkoutProgram;
use App\Models\WorkoutProgramEnduranceDetail;
use App\Models\WorkoutProgramExercise;
use App\Models\WorkoutProgramTimedDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WorkoutProgramService
{
    public function __construct(private readonly ExerciseCatalogueService $catalogue) {}

    /** @param list<array<string, mixed>> $items */
    public function replaceExercises(WorkoutProgram $program, User $user, array $items): WorkoutProgram
    {
        $this->assertOwned($program, $user);
        if ($program->workout_type !== WorkoutProgram::TYPE_STRENGTH) {
            throw ValidationException::withMessages(['exercises' => __('messages.workout_strength_only')]);
        }

        $seenExercises = [];
        $seenOrders = [];
        foreach ($items as $index => $item) {
            $exerciseId = (int) ($item['exercise_id'] ?? 0);
            $order = (int) ($item['sort_order'] ?? -1);
            try {
                $this->catalogue->assertAccessible($exerciseId, $user);
            } catch (ValidationException) {
                throw ValidationException::withMessages([
                    "exercises.{$index}.exercise_id" => __('messages.workout_exercise_unavailable'),
                ]);
            }
            if (in_array($exerciseId, $seenExercises, true) || in_array($order, $seenOrders, true)) {
                throw ValidationException::withMessages(["exercises.{$index}" => __('messages.workout_duplicate_item')]);
            }
            $seenExercises[] = $exerciseId;
            $seenOrders[] = $order;
        }

        DB::transaction(function () use ($program, $user, $items): void {
            $program->exercises()->delete();
            foreach ($items as $item) {
                WorkoutProgramExercise::create(['user_id' => $user->id, 'workout_program_id' => $program->id, ...$item]);
            }
        });

        return $program->fresh(['recurringRule.ruleWeekdays', 'exercises.exercise', 'enduranceDetail', 'timedDetail']);
    }

    /** @param array<string, mixed>|null $endurance @param array<string, mixed>|null $timed */
    public function replaceSubtype(WorkoutProgram $program, User $user, ?array $endurance, ?array $timed): void
    {
        $this->assertOwned($program, $user);
        DB::transaction(function () use ($program, $user, $endurance, $timed): void {
            $program->enduranceDetail()->delete();
            $program->timedDetail()->delete();
            if ($program->workout_type === WorkoutProgram::TYPE_CARDIO && $endurance !== null) {
                WorkoutProgramEnduranceDetail::create([
                    'user_id' => $user->id, 'workout_program_id' => $program->id, ...$endurance,
                ]);
            }
            if (in_array($program->workout_type, [WorkoutProgram::TYPE_FLEXIBILITY, WorkoutProgram::TYPE_SPORT], true)
                && $timed !== null) {
                WorkoutProgramTimedDetail::create([
                    'user_id' => $user->id, 'workout_program_id' => $program->id, ...$timed,
                ]);
            }
        });
    }

    private function assertOwned(WorkoutProgram $program, User $user): void
    {
        if ((int) $program->user_id !== (int) $user->id) {
            throw new NotFoundHttpException;
        }
    }
}
