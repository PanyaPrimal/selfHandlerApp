<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('entries');

        return [
            'id' => $this->id,
            'consumed_on' => $this->consumed_on->toDateString(),
            'name' => $this->name,
            'category' => $this->category,
            'consumed_at_local' => $this->consumed_at_local === null ? null : substr((string) $this->consumed_at_local, 0, 5),
            'note' => $this->note,
            'submission_key' => $this->submission_key,
            'entries' => $this->entries->map(fn ($entry): array => [
                'id' => $entry->id,
                'food_item_id' => $entry->food_item_id,
                'recipe_id' => $entry->recipe_id,
                'sort_order' => $entry->sort_order,
                'reference_name' => $entry->reference_name,
                'basis_unit' => $entry->basis_unit,
                'quantity' => $entry->quantity,
                'calories' => $entry->calories,
                'protein_grams' => $entry->protein_grams,
                'fat_grams' => $entry->fat_grams,
                'carbs_grams' => $entry->carbs_grams,
                'hydration_ml' => $entry->hydration_ml,
                'quality_numerator' => $entry->quality_numerator,
                'quality_denominator' => $entry->quality_denominator,
            ])->values()->all(),
        ];
    }
}
