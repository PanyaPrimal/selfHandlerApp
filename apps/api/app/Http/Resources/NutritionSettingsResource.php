<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NutritionSettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'body_goal_id' => $this->body_goal_id,
            'protein_percent' => $this->protein_percent,
            'fat_percent' => $this->fat_percent,
            'carbs_percent' => $this->carbs_percent,
            'water_override_ml' => $this->water_override_ml,
        ];
    }
}
