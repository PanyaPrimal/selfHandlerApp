<?php

namespace App\Http\Requests;

class NutritionSettingsRequest extends StrictJsonRequest
{
    public function rules(): array
    {
        return [
            'body_goal_id' => ['present', 'nullable', 'integer'],
            'protein_percent' => ['required', 'numeric', 'between:10,35'],
            'fat_percent' => ['required', 'numeric', 'between:20,35'],
            'carbs_percent' => ['required', 'numeric', 'between:45,65'],
            'water_override_ml' => ['present', 'nullable', 'integer', 'between:1000,6000'],
        ];
    }

    protected function allowedKeys(): array
    {
        return ['body_goal_id', 'protein_percent', 'fat_percent', 'carbs_percent', 'water_override_ml'];
    }
}
