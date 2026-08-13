<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplementOccurrenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $course = $this->getAttribute('course_projection');
        $supplement = $course->supplement;
        $effective = $this->rescheduled_to ?? $this->occurrence_date;

        return [
            'id' => $this->id,
            'course_id' => $course->id,
            'course_name' => $course->name ?: $supplement->name,
            'supplement_id' => $supplement->id,
            'supplement_name' => $supplement->name,
            'stock_unit' => $supplement->stock_unit,
            'occurrence_date' => $this->occurrence_date->format('Y-m-d'),
            'rescheduled_to' => $this->rescheduled_to?->format('Y-m-d'),
            'effective_date' => $effective->format('Y-m-d'),
            'slot' => $this->slot,
            'time' => substr((string) $this->occurrence_time, 0, 5),
            'intake_context' => $this->getAttribute('intake_context_projection') ?? 'unspecified',
            'status' => $this->status,
            'dose_quantity' => $course->dose_quantity,
            'dose_display_unit' => $course->dose_display_unit,
            'actions' => $this->hasFact()
                ? ['correct', 'clear']
                : ['take', 'skip', 'reschedule'],
            'intake' => $this->supplementIntake
                ? SupplementIntakeResource::make($this->supplementIntake)->resolve($request)
                : null,
        ];
    }
}
