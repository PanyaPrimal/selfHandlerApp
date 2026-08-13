<?php

namespace App\Services;

use App\Models\Recipe;
use App\Support\NutritionDecimal;

class RecipeNutritionService
{
    /** @return array{total_weight_grams:string,calories:string,protein_grams:string,fat_grams:string,carbs_grams:string,quality_score:?string} */
    public function calculate(Recipe $recipe): array
    {
        $recipe->loadMissing('components.food');
        $total = '0.000';
        $calories = '0.000000';
        $protein = '0.000000';
        $fat = '0.000000';
        $carbs = '0.000000';
        $qualityNumerator = '0.00000';
        $qualityDenominator = '0.000';
        foreach ($recipe->components as $component) {
            $quantity = $component->quantity_grams;
            $total = NutritionDecimal::add($total, $quantity, 3);
            $calories = NutritionDecimal::add($calories,
                NutritionDecimal::multiply($component->food->calories_per_100, $quantity, 6), 6);
            $protein = NutritionDecimal::add($protein,
                NutritionDecimal::multiply($component->food->protein_per_100, $quantity, 6), 6);
            $fat = NutritionDecimal::add($fat,
                NutritionDecimal::multiply($component->food->fat_per_100, $quantity, 6), 6);
            $carbs = NutritionDecimal::add($carbs,
                NutritionDecimal::multiply($component->food->carbs_per_100, $quantity, 6), 6);
            if ($component->food->quality_score !== null) {
                $qualityNumerator = NutritionDecimal::add($qualityNumerator,
                    NutritionDecimal::multiply($component->food->quality_score, $quantity, 5), 5);
                $qualityDenominator = NutritionDecimal::add($qualityDenominator, $quantity, 3);
            }
        }

        return [
            'total_weight_grams' => NutritionDecimal::format($total, 3),
            'calories' => NutritionDecimal::divide($calories, $total, 3),
            'protein_grams' => NutritionDecimal::divide($protein, $total, 3),
            'fat_grams' => NutritionDecimal::divide($fat, $total, 3),
            'carbs_grams' => NutritionDecimal::divide($carbs, $total, 3),
            'quality_score' => bccomp($qualityDenominator, '0', 3) > 0
                ? NutritionDecimal::divide($qualityNumerator, $qualityDenominator, 2) : null,
        ];
    }
}
