<?php

namespace App\Http\Requests;

use App\Models\FoodItem;
use Illuminate\Validation\Rule;

class FoodItemMutationRequest extends StrictJsonRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:160'],
            'basis_unit' => [$required, Rule::in([FoodItem::BASIS_GRAM, FoodItem::BASIS_MILLILITRE])],
            'is_beverage' => [$required, 'boolean'],
            'calories_per_100' => [$required, 'numeric', 'between:0,1000000'],
            'protein_per_100' => [$required, 'numeric', 'between:0,1000000'],
            'fat_per_100' => [$required, 'numeric', 'between:0,1000000'],
            'carbs_per_100' => [$required, 'numeric', 'between:0,1000000'],
            'quality_score' => [$this->isMethod('post') ? 'present' : 'sometimes', 'nullable', 'numeric', 'between:0,100'],
            'hydration_ratio' => [$required, 'numeric', 'between:0,1'],
            'is_archived' => ['sometimes', 'boolean'],
        ];
    }

    protected function allowedKeys(): array
    {
        $keys = ['name', 'basis_unit', 'is_beverage', 'calories_per_100', 'protein_per_100',
            'fat_per_100', 'carbs_per_100', 'quality_score', 'hydration_ratio'];
        if (! $this->isMethod('post')) {
            $keys[] = 'is_archived';
        }

        return $keys;
    }
}
