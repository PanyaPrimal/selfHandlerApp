<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NutritionTargetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'date' => $this->target_date->toDateString(),
            'status' => $this->status,
            'formula' => $this->formula,
            'bmr_kcal' => $this->bmr_kcal,
            'baseline_kcal' => $this->baseline_kcal,
            'goal_adjustment_kcal' => $this->goal_adjustment_kcal,
            'planned_workout_kcal' => $this->planned_workout_kcal,
            'calorie_target' => $this->calorie_target,
            'protein_target_grams' => $this->protein_target_grams,
            'fat_target_grams' => $this->fat_target_grams,
            'carbs_target_grams' => $this->carbs_target_grams,
            'water_target_ml' => $this->water_target_ml,
            'quality_target' => $this->quality_target,
            'calculation_basis' => $this->calculation_basis,
        ];
    }
}
