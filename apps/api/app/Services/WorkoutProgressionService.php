<?php

namespace App\Services;

use App\Models\WorkoutProgram;
use App\Models\WorkoutProgramExercise;
use App\Models\WorkoutSession;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class WorkoutProgressionService
{
    /** @return array<int, array<string, int|string>> */
    public function forProgram(WorkoutProgram $program, string $throughDate): array
    {
        return $this->forPrograms(new EloquentCollection([$program]), $throughDate)[$program->id] ?? [];
    }

    /**
     * @param  Collection<int, WorkoutProgram>  $programs
     * @return array<int, array<int, array<string, int|string>>>
     */
    public function forPrograms(Collection $programs, string $throughDate): array
    {
        if ($programs->isEmpty()) {
            return [];
        }

        $programs = new EloquentCollection($programs->all());
        $programs->load('exercises.exercise');
        $sessions = WorkoutSession::query()
            ->whereIn('workout_program_id', $programs->modelKeys())
            ->where('outcome', WorkoutSession::OUTCOME_COMPLETED)
            ->whereDate('performed_on', '<=', $throughDate)
            ->with(['strengthDetail.exercises.sets'])
            ->orderBy('performed_on')->orderBy('id')->get()
            ->groupBy('workout_program_id');

        $result = [];
        foreach ($programs as $program) {
            $result[$program->id] = $this->fold(
                $program->exercises->sortBy('sort_order')->values(),
                $sessions->get($program->id, collect()),
            );
        }

        return $result;
    }

    /**
     * @param  Collection<int, WorkoutProgramExercise>  $prescriptions
     * @param  Collection<int, WorkoutSession>  $sessions
     * @return array<int, array<string, int|string>>
     */
    private function fold(Collection $prescriptions, Collection $sessions): array
    {
        $result = [];
        foreach ($prescriptions as $prescription) {
            $target = (float) $prescription->starting_weight_kg;
            $successes = 0;
            foreach ($sessions as $session) {
                $actual = $session->strengthDetail?->exercises
                    ->firstWhere('exercise_id', $prescription->exercise_id);
                if (! $actual) {
                    continue;
                }
                if ($this->qualifies($actual, $prescription, $target)) {
                    $successes++;
                    if ($successes >= $prescription->successes_required) {
                        $target += (float) $prescription->increment_kg;
                        $successes = 0;
                    }
                } else {
                    $successes = 0;
                }
            }
            $result[$prescription->id] = [
                'next_weight_kg' => number_format($target, 3, '.', ''),
                'successful_sessions' => $successes,
                'successes_required' => $prescription->successes_required,
                'successes_remaining' => $prescription->successes_required - $successes,
            ];
        }

        return $result;
    }

    private function qualifies($actual, WorkoutProgramExercise $prescription, float $target): bool
    {
        if ($actual->simple_weight_kg !== null) {
            return (float) $actual->simple_weight_kg >= $target
                && $actual->simple_reps >= $prescription->target_reps;
        }

        return $actual->sets->filter(fn ($set): bool => (float) $set->weight_kg >= $target
            && $set->reps >= $prescription->target_reps)->count() >= $prescription->target_sets;
    }
}
