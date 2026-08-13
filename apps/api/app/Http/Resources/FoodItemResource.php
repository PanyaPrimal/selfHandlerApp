<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FoodItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'system_key' => $this->system_key,
            'name' => $this->name,
            'basis_unit' => $this->basis_unit,
            'is_beverage' => $this->is_beverage,
            'calories_per_100' => $this->calories_per_100,
            'protein_per_100' => $this->protein_per_100,
            'fat_per_100' => $this->fat_per_100,
            'carbs_per_100' => $this->carbs_per_100,
            'quality_score' => $this->quality_score,
            'hydration_ratio' => $this->hydration_ratio,
            'is_archived' => $this->is_archived,
            'is_public' => $this->user_id === null,
        ];
    }
}
