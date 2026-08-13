<?php

namespace App\Services;

use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\User;
use App\Models\WorkoutSession;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class WorkoutStatisticsService
{
    /** @return array<string, mixed> */
    public function forRange(User $user, string $from, string $to, ?int $programId = null): array
    {
        $start = $this->date($from, 'from');
        $end = $this->date($to, 'to');
        if ($start->greaterThan($end) || $start->diffInDays($end) > 365) {
            throw ValidationException::withMessages(['range' => __('messages.workout_range_invalid')]);
        }

        $sessionsQuery = WorkoutSession::query()->ownedBy($user)
            ->whereBetween('performed_on', [$from, $to])
            ->when($programId !== null, fn ($query) => $query->where('workout_program_id', $programId))
            ->with([
                'program', 'plannedOccurrence', 'strengthDetail.exercises.exercise',
                'strengthDetail.exercises.sets', 'enduranceDetail', 'timedDetail',
            ])->orderByDesc('performed_on')->orderByDesc('id');
        $sessions = $sessionsQuery->get();

        $programs = RecurringRule::query()->ownedBy($user)
            ->where('owner_type', RecurringRule::OWNER_WORKOUT_PROGRAM)
            ->when($programId !== null, fn ($query) => $query->where('owner_id', $programId))
            ->pluck('id');
        $occurrences = PlannedOccurrence::query()
            ->whereIn('recurring_rule_id', $programs)
            ->where(function ($query) use ($from, $to): void {
                $query->where(function ($original) use ($from, $to): void {
                    $original->whereBetween('occurrence_date', [$from, $to])->whereNull('rescheduled_to');
                })->orWhereBetween('rescheduled_to', [$from, $to]);
            })->get(['id', 'status']);

        $completed = $sessions->where('outcome', WorkoutSession::OUTCOME_COMPLETED);
        $distance = $completed->sum(fn (WorkoutSession $session): int => $session->enduranceDetail?->distance_m ?? 0);
        $duration = $completed->sum(fn (WorkoutSession $session): int => $session->duration_seconds ?? 0);
        $volume = 0.0;
        $exerciseRecords = [];
        $paceRecords = [];
        foreach ($completed as $session) {
            foreach ($session->strengthDetail?->exercises ?? [] as $exercise) {
                $weights = $exercise->sets->pluck('weight_kg')->map(fn ($value): float => (float) $value);
                $sessionVolume = 0.0;
                if ($exercise->simple_weight_kg !== null) {
                    $weights->push((float) $exercise->simple_weight_kg);
                    $sessionVolume += (float) $exercise->simple_weight_kg * (int) $exercise->simple_reps;
                }
                foreach ($exercise->sets as $set) {
                    $sessionVolume += (float) $set->weight_kg * $set->reps;
                }
                $volume += $sessionVolume;
                $max = $weights->max();
                $existing = $exerciseRecords[$exercise->exercise_id]['max_weight_kg'] ?? null;
                if ($max !== null && ($existing === null || $max > (float) $existing)) {
                    $exerciseRecords[$exercise->exercise_id] = [
                        'exercise' => $exercise->exercise,
                        'max_weight_kg' => number_format($max, 3, '.', ''),
                        'max_volume_kg' => number_format($sessionVolume, 3, '.', ''),
                        'recorded_on' => $session->performed_on->format('Y-m-d'),
                    ];
                } elseif (isset($exerciseRecords[$exercise->exercise_id])
                    && $sessionVolume > (float) $exerciseRecords[$exercise->exercise_id]['max_volume_kg']) {
                    $exerciseRecords[$exercise->exercise_id]['max_volume_kg'] = number_format($sessionVolume, 3, '.', '');
                }
            }
            $endurance = $session->enduranceDetail;
            if ($endurance && $endurance->distance_m > 0 && $session->duration_seconds > 0) {
                $pace = (int) round($session->duration_seconds / ($endurance->distance_m / 1000));
                $existing = $paceRecords[$endurance->activity]['best_pace_seconds_per_km'] ?? null;
                if ($existing === null || $pace < $existing) {
                    $paceRecords[$endurance->activity] = [
                        'activity' => $endurance->activity,
                        'best_pace_seconds_per_km' => $pace,
                        'recorded_on' => $session->performed_on->format('Y-m-d'),
                    ];
                }
            }
        }

        return [
            'sessions' => $sessions->all(),
            'summary' => [
                'planned' => $occurrences->count(),
                'completed' => $occurrences->where('status', PlannedOccurrence::STATUS_DONE)->count(),
                'skipped' => $occurrences->where('status', PlannedOccurrence::STATUS_SKIPPED)->count(),
                'unplanned' => $completed->whereNull('workout_program_id')->count(),
                'duration_seconds' => $duration,
                'distance_m' => $distance,
                'strength_volume_kg' => number_format($volume, 3, '.', ''),
            ],
            'records' => [
                'exercises' => array_values($exerciseRecords),
                'paces' => array_values($paceRecords),
            ],
        ];
    }

    private function date(string $value, string $field): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (\Throwable) {
            $date = false;
        }
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([$field => __('messages.workout_date_invalid')]);
        }

        return $date;
    }
}
