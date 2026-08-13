<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrainingGoalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'status' => $this->status,
            'target_date' => $this->target_date?->format('Y-m-d'),
            'completed_at' => $this->completed_at?->toISOString(),
            'is_archived' => $this->is_archived,
            'archived_at' => $this->archived_at?->toISOString(),
            'training' => $this->getAttribute('training_progress_projection'),
        ];
    }
}
