<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExerciseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'system_key' => $this->system_key,
            'name' => $this->name,
            'display_key' => $this->system_key === null ? null : 'workouts.exercises.'.$this->system_key,
            'muscle_group' => $this->muscle_group,
            'equipment' => $this->equipment,
            'exercise_type' => $this->exercise_type,
            'is_builtin' => $this->system_key !== null,
            'is_archived' => $this->is_archived,
            'archived_at' => $this->archived_at?->toISOString(),
        ];
    }
}
