<?php

namespace App\Services;

use App\Models\FoodItem;
use App\Models\Recipe;
use App\Models\RecipeComponent;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RecipeService
{
    public function list(User $user, string $state = 'active'): Collection
    {
        return Recipe::query()->ownedBy($user)
            ->when($state === 'active', fn ($query) => $query->where('is_archived', false))
            ->when($state === 'archived', fn ($query) => $query->where('is_archived', true))
            ->with('components.food')->orderBy('name')->orderBy('id')->get();
    }

    /** @param array<string, mixed> $attributes */
    public function create(User $user, array $attributes): Recipe
    {
        $data = $this->validate($user, $attributes);

        return DB::transaction(function () use ($user, $data): Recipe {
            $components = $data['components'];
            unset($data['components']);
            $recipe = Recipe::create(['user_id' => $user->id, ...$data]);
            $this->replace($recipe, $user, $components);

            return $recipe->fresh('components.food');
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Recipe $recipe, User $user, array $attributes): Recipe
    {
        $recipe = Recipe::query()->ownedBy($user)->findOrFail($recipe->id);
        $merged = [...$recipe->only(['name', 'description']), ...$attributes];
        if (! array_key_exists('components', $merged)) {
            $merged['components'] = $recipe->components()->orderBy('sort_order')->get()
                ->map(fn ($row) => ['food_item_id' => $row->food_item_id, 'quantity_grams' => $row->quantity_grams])->all();
        }
        $data = $this->validate($user, $merged, $recipe);

        return DB::transaction(function () use ($recipe, $user, $data, $attributes): Recipe {
            $components = $data['components'];
            unset($data['components']);
            $recipe->fill($data);
            if (array_key_exists('is_archived', $attributes)) {
                $recipe->applyLifecycle((bool) $attributes['is_archived']);
            }
            $recipe->save();
            if (array_key_exists('components', $attributes)) {
                $this->replace($recipe, $user, $components);
            }

            return $recipe->fresh('components.food');
        });
    }

    /** @param list<array<string, mixed>> $components */
    private function replace(Recipe $recipe, User $user, array $components): void
    {
        $recipe->components()->delete();
        foreach ($components as $index => $component) {
            RecipeComponent::create([
                'user_id' => $user->id, 'recipe_id' => $recipe->id,
                'food_item_id' => $component['food_item_id'], 'sort_order' => $index,
                'quantity_grams' => $component['quantity_grams'],
            ]);
        }
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private function validate(User $user, array $attributes, ?Recipe $recipe = null): array
    {
        $validator = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['present', 'nullable', 'string', 'max:1000'],
            'components' => ['required', 'array', 'min:1', 'max:100'],
            'components.*' => ['array:food_item_id,quantity_grams'],
            'components.*.food_item_id' => ['required', 'integer', 'distinct'],
            'components.*.quantity_grams' => ['required', 'numeric', 'gt:0', 'max:1000000'],
        ]);
        $validator->after(function ($validator) use ($attributes, $user, $recipe): void {
            if (Recipe::query()->ownedBy($user)->where('name', $attributes['name'] ?? '')
                ->when($recipe, fn ($query) => $query->whereKeyNot($recipe->id))->exists()) {
                $validator->errors()->add('name', __('validation.unique', ['attribute' => 'name']));
            }
            foreach ((array) ($attributes['components'] ?? []) as $index => $component) {
                $food = FoodItem::query()->whereKey($component['food_item_id'] ?? 0)
                    ->where(function ($query) use ($user): void {
                        $query->whereNull('user_id')->orWhere('user_id', $user->id);
                    })->first();
                if (! $food || $food->is_archived || $food->is_beverage || $food->basis_unit !== FoodItem::BASIS_GRAM) {
                    $validator->errors()->add("components.{$index}.food_item_id", __('messages.nutrition_reference_unavailable'));
                }
            }
        });

        return $validator->validate();
    }
}
