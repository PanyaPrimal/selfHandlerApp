<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkoutSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $timezone = $this->user->calendarTimezone();
        $volume = 0.0;
        $strength = null;
        if ($this->strengthDetail) {
            $strength = [
                'mode' => $this->strengthDetail->mode,
                'exercises' => $this->strengthDetail->exercises->map(function ($row) use ($request, &$volume): array {
                    if ($row->simple_weight_kg !== null) {
                        $volume += (float) $row->simple_weight_kg * $row->simple_reps;
                    }
                    foreach ($row->sets as $set) {
                        $volume += (float) $set->weight_kg * $set->reps;
                    }

                    return [
                        'id' => $row->id,
                        'exercise' => ExerciseResource::make($row->exercise)->resolve($request),
                        'sort_order' => $row->sort_order,
                        'simple_weight_kg' => $row->simple_weight_kg,
                        'simple_reps' => $row->simple_reps,
                        'note' => $row->note,
                        'sets' => $row->sets->map(fn ($set): array => [
                            'id' => $set->id, 'set_order' => $set->set_order,
                            'weight_kg' => $set->weight_kg, 'reps' => $set->reps,
                            'rest_seconds' => $set->rest_seconds,
                        ])->all(),
                    ];
                })->all(),
            ];
        }
        $endurance = $this->enduranceDetail ? [
            'activity' => $this->enduranceDetail->activity,
            'run_type' => $this->enduranceDetail->run_type,
            'distance_m' => $this->enduranceDetail->distance_m,
            'average_heart_rate' => $this->enduranceDetail->average_heart_rate,
            'energy_kcal' => $this->enduranceDetail->energy_kcal,
            'pace_seconds_per_km' => $this->enduranceDetail->distance_m && $this->duration_seconds
                ? (int) round($this->duration_seconds / ($this->enduranceDetail->distance_m / 1000)) : null,
        ] : null;

        return [
            'id' => $this->id,
            'workout_program_id' => $this->workout_program_id,
            'planned_occurrence_id' => $this->plannedOccurrence?->id,
            'name' => $this->name,
            'workout_type' => $this->workout_type,
            'outcome' => $this->outcome,
            'performed_on' => $this->performed_on->format('Y-m-d'),
            'started_at' => $this->started_at?->toISOString(),
            'started_time' => $this->started_at?->setTimezone($timezone)->format('H:i'),
            'duration_seconds' => $this->duration_seconds,
            'note' => $this->note,
            'strength' => $strength,
            'endurance' => $endurance,
            'timed' => $this->timedDetail ? ['activity_name' => $this->timedDetail->activity_name] : null,
            'totals' => [
                'duration_seconds' => $this->outcome === 'completed' ? ($this->duration_seconds ?? 0) : 0,
                'distance_m' => $this->outcome === 'completed' ? ($this->enduranceDetail?->distance_m ?? 0) : 0,
                'strength_volume_kg' => number_format($volume, 3, '.', ''),
            ],
        ];
    }
}
