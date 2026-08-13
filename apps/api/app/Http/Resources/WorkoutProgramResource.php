<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkoutProgramResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $rule = $this->recurringRule;
        $occurrence = $this->getAttribute('occurrence_projection');
        $progression = $this->getAttribute('progression_projection') ?? [];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'workout_type' => $this->workout_type,
            'intensity' => $this->intensity,
            'planned_duration_seconds' => $this->planned_duration_seconds,
            'is_active' => $this->is_active,
            'is_archived' => $this->is_archived,
            'archived_at' => $this->archived_at?->toISOString(),
            'recurring_rule' => [
                'id' => $rule->id,
                'frequency' => $rule->frequency,
                'schedule_type' => $rule->scheduleType(),
                'starts_on' => $rule->starts_on?->format('Y-m-d'),
                'ends_on' => $rule->ends_on?->format('Y-m-d'),
                'timezone' => $rule->timezone,
                'slot_time' => $rule->slot_time ? substr((string) $rule->slot_time, 0, 5) : null,
                'weekdays' => $rule->weekdays,
                'last_materialized_until' => $rule->last_materialized_until?->format('Y-m-d'),
            ],
            'exercises' => $this->exercises->map(fn ($row): array => [
                'id' => $row->id,
                'exercise' => ExerciseResource::make($row->exercise)->resolve($request),
                'sort_order' => $row->sort_order,
                'target_sets' => $row->target_sets,
                'target_reps' => $row->target_reps,
                'starting_weight_kg' => $row->starting_weight_kg,
                'increment_kg' => $row->increment_kg,
                'successes_required' => $row->successes_required,
                'progression' => $progression[$row->id] ?? [
                    'next_weight_kg' => $row->starting_weight_kg,
                    'successful_sessions' => 0,
                    'successes_required' => $row->successes_required,
                    'successes_remaining' => $row->successes_required,
                ],
            ])->all(),
            'endurance' => $this->enduranceDetail ? [
                'activity' => $this->enduranceDetail->activity,
                'run_type' => $this->enduranceDetail->run_type,
                'target_distance_m' => $this->enduranceDetail->target_distance_m,
            ] : null,
            'timed' => $this->timedDetail ? ['activity_name' => $this->timedDetail->activity_name] : null,
            'selected_date' => $this->getAttribute('selected_date_projection'),
            'occurrence' => $occurrence ? [
                'id' => $occurrence->id,
                'occurrence_date' => $occurrence->occurrence_date->format('Y-m-d'),
                'effective_date' => $occurrence->rescheduled_to?->format('Y-m-d')
                    ?? $occurrence->occurrence_date->format('Y-m-d'),
                'time' => $occurrence->occurrence_time ? substr((string) $occurrence->occurrence_time, 0, 5) : null,
                'status' => $occurrence->status,
                'workout_session_id' => $occurrence->workout_session_id,
            ] : null,
        ];
    }
}
