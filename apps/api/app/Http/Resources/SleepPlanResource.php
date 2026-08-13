<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SleepPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'planned_wake_time' => substr((string) $this->planned_wake_time, 0, 5),
            'is_active' => $this->is_active,
            'is_archived' => $this->is_archived,
            'archived_at' => $this->archived_at?->toISOString(),
            'schedule' => [
                'schedule_type' => $this->recurringRule?->scheduleType() ?? 'daily',
                'weekdays' => $this->recurringRule?->weekdays ?? [],
                'planned_bed_time' => $this->recurringRule?->slot_time
                    ? substr((string) $this->recurringRule->slot_time, 0, 5)
                    : null,
                'starts_on' => $this->recurringRule?->starts_on?->format('Y-m-d'),
                'ends_on' => $this->recurringRule?->ends_on?->format('Y-m-d'),
            ],
            'selected_night' => $this->relationLoaded('selectedNightPayload')
                ? $this->getRelation('selectedNightPayload')
                : null,
        ];
    }
}
