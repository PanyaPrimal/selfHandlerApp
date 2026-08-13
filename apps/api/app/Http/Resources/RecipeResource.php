<?php

namespace App\Http\Resources;

use App\Services\RecipeNutritionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecipeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('components.food');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_archived' => $this->is_archived,
            'components' => $this->components->map(fn ($component): array => [
                'id' => $component->id,
                'sort_order' => $component->sort_order,
                'quantity_grams' => $component->quantity_grams,
                'food' => FoodItemResource::make($component->food)->resolve($request),
            ])->values()->all(),
            'nutrition_per_100' => app(RecipeNutritionService::class)->calculate($this->resource),
        ];
    }
}
