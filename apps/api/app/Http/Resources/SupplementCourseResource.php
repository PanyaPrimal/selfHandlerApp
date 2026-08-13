<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplementCourseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $rule = $this->recurringRule;
        $cycle = $rule?->cycle_on_days !== null && $rule?->cycle_off_days !== null
            ? ['on_days' => $rule->cycle_on_days, 'off_days' => $rule->cycle_off_days]
            : null;

        return [
            'id' => $this->id,
            'supplement_id' => $this->supplement_id,
            'supplement_name' => $this->supplement->name,
            'stock_unit' => $this->supplement->stock_unit,
            'goal_id' => $this->goal_id,
            'name' => $this->name,
            'dose_quantity' => $this->dose_quantity,
            'dose_display_unit' => $this->dose_display_unit,
            'starts_on' => $this->starts_on->format('Y-m-d'),
            'ends_on' => $this->ends_on->format('Y-m-d'),
            'is_active' => $this->is_active,
            'is_archived' => $this->is_archived,
            'archived_at' => $this->archived_at?->toISOString(),
            'schedule' => [
                'frequency' => $rule?->frequency,
                'interval_count' => $rule?->interval_count ?? 1,
                'weekdays' => $rule?->weekdays ?? [],
                'cycle' => $cycle,
                'timezone' => $rule?->timezone,
                'slots' => $rule?->ruleSlots->map(fn ($slot): array => [
                    'id' => $slot->id,
                    'slot' => $slot->slot,
                    'time' => substr((string) $slot->occurrence_time, 0, 5),
                    'intake_context' => $slot->supplementDetail?->intake_context ?? 'unspecified',
                    'sort_order' => $slot->sort_order,
                ])->values()->all() ?? [],
                'materialized_until' => $rule?->last_materialized_until?->format('Y-m-d'),
            ],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
