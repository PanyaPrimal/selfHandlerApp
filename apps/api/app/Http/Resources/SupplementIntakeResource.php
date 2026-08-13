<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplementIntakeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplement_course_id' => $this->supplement_course_id,
            'supplement_id' => $this->supplement_id,
            'planned_on' => $this->planned_on->format('Y-m-d'),
            'effective_on' => $this->effective_on->format('Y-m-d'),
            'slot' => $this->slot,
            'outcome' => $this->outcome,
            'dose_quantity' => $this->dose_quantity,
            'dose_display_unit' => $this->dose_display_unit,
            'supplement_name' => $this->supplement_name,
            'taken_at' => $this->taken_at?->toISOString(),
            'taken_time' => $this->taken_at?->setTimezone($request->user()->calendarTimezone())->format('H:i'),
            'note' => $this->note,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
