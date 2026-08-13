<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HabitResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $rule = $this->recurringRule;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'kind' => $this->kind,
            'mode' => $this->mode,
            'target_value' => $this->target_value === null ? null : (float) $this->target_value,
            'unit' => $this->unit,
            'schedule' => [
                'schedule_type' => $rule?->scheduleType() ?? 'daily',
                'weekdays' => $rule?->weekdays ?? [],
                'preferred_time' => $rule?->slot_time ? substr((string) $rule->slot_time, 0, 5) : null,
                'starts_on' => $rule?->starts_on?->format('Y-m-d'),
                'ends_on' => $rule?->ends_on?->format('Y-m-d'),
                'timezone' => $rule?->timezone,
                'materialized_until' => $rule?->last_materialized_until?->format('Y-m-d'),
            ],
            'routine' => $this->routine ? ['id' => $this->routine->id, 'name' => $this->routine->name] : null,
            'goal' => $this->goal ? ['id' => $this->goal->id, 'name' => $this->goal->name] : null,
            'intention_place' => $this->intention_place,
            'two_minute_starter' => $this->two_minute_starter,
            'is_active' => $this->is_active,
            'is_archived' => $this->is_archived,
            'archived_at' => $this->archived_at?->toISOString(),
            'limit_steps' => $this->getAttribute('limit_steps_projection') ?? [],
            'selected_day' => $this->getAttribute('selected_day_projection'),
            'statistics' => $this->getAttribute('statistics_projection'),
            'limit_status' => $this->getAttribute('limit_status_projection'),
        ];
    }
}
