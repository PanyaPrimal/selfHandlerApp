<?php

namespace App\Services;

use App\Models\FoodItem;
use App\Models\Meal;
use App\Models\MealEntry;
use App\Models\Recipe;
use App\Models\User;
use App\Support\NutritionDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MealService
{
    public function __construct(private readonly RecipeNutritionService $recipeNutrition) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $user, array $attributes): Meal
    {
        if (isset($attributes['submission_key'])) {
            $existing = Meal::query()->ownedBy($user)->where('submission_key', $attributes['submission_key'])->first();
            if ($existing) {
                return $existing->load('entries');
            }
        }
        $data = $this->validate($user, $attributes, true);

        return DB::transaction(function () use ($user, $data): Meal {
            $entries = $data['entries'];
            unset($data['entries']);
            $meal = Meal::create(['user_id' => $user->id, ...$data]);
            $this->replaceEntries($meal, $user, $entries);

            return $meal->fresh('entries');
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Meal $meal, User $user, array $attributes): Meal
    {
        $meal = Meal::query()->ownedBy($user)->findOrFail($meal->id);
        $data = $this->validate($user, $attributes, false);

        return DB::transaction(function () use ($meal, $user, $data): Meal {
            $entries = $data['entries'];
            unset($data['entries']);
            $meal->update($data);
            $this->replaceEntries($meal, $user, $entries);

            return $meal->fresh('entries');
        });
    }

    public function delete(Meal $meal, User $user): void
    {
        Meal::query()->ownedBy($user)->findOrFail($meal->id)->delete();
    }

    /** @param list<array<string, mixed>> $entries */
    private function replaceEntries(Meal $meal, User $user, array $entries): void
    {
        $snapshots = array_map(fn (array $entry, int $index): array => $this->snapshot($user, $entry, $index), $entries, array_keys($entries));
        $meal->entries()->delete();
        foreach ($snapshots as $snapshot) {
            MealEntry::create(['user_id' => $user->id, 'meal_id' => $meal->id, ...$snapshot]);
        }
    }

    /** @param array<string, mixed> $entry @return array<string, mixed> */
    private function snapshot(User $user, array $entry, int $index): array
    {
        $foodId = $entry['food_item_id'] ?? null;
        $recipeId = $entry['recipe_id'] ?? null;
        $quantity = NutritionDecimal::format($entry['quantity'], 3);
        if ($foodId !== null) {
            $food = FoodItem::query()->whereKey($foodId)
                ->where(function ($query) use ($user): void {
                    $query->whereNull('user_id')->orWhere('user_id', $user->id);
                })->where('is_archived', false)->firstOrFail();
            $values = [
                'calories' => $food->calories_per_100,
                'protein_grams' => $food->protein_per_100,
                'fat_grams' => $food->fat_per_100,
                'carbs_grams' => $food->carbs_per_100,
            ];
            $hydration = $food->is_beverage
                ? NutritionDecimal::multiply($quantity, $food->hydration_ratio, 3) : '0.000';
            $quality = ! $food->is_beverage ? $food->quality_score : null;
            $basis = $food->basis_unit;
            $name = $food->name;
        } else {
            $recipe = Recipe::query()->ownedBy($user)->whereKey($recipeId)
                ->where('is_archived', false)->with('components.food')->firstOrFail();
            $nutrition = $this->recipeNutrition->calculate($recipe);
            $values = [
                'calories' => $nutrition['calories'],
                'protein_grams' => $nutrition['protein_grams'],
                'fat_grams' => $nutrition['fat_grams'],
                'carbs_grams' => $nutrition['carbs_grams'],
            ];
            $hydration = '0.000';
            $quality = $nutrition['quality_score'];
            $basis = FoodItem::BASIS_GRAM;
            $name = $recipe->name;
        }

        return [
            'food_item_id' => $foodId, 'recipe_id' => $recipeId, 'sort_order' => $index,
            'reference_name' => $name, 'basis_unit' => $basis,
            'quantity' => $quantity,
            'calories' => NutritionDecimal::divide(NutritionDecimal::multiply($values['calories'], $quantity, 6), 100, 3),
            'protein_grams' => NutritionDecimal::divide(NutritionDecimal::multiply($values['protein_grams'], $quantity, 6), 100, 3),
            'fat_grams' => NutritionDecimal::divide(NutritionDecimal::multiply($values['fat_grams'], $quantity, 6), 100, 3),
            'carbs_grams' => NutritionDecimal::divide(NutritionDecimal::multiply($values['carbs_grams'], $quantity, 6), 100, 3),
            'hydration_ml' => $hydration,
            'quality_numerator' => $quality === null ? null : NutritionDecimal::multiply($quality, $quantity, 4),
            'quality_denominator' => $quality === null ? '0.000' : $quantity,
        ];
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private function validate(User $user, array $attributes, bool $creating): array
    {
        $rules = [
            'consumed_on' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:160'],
            'category' => ['present', 'nullable', 'in:breakfast,lunch,dinner,snack,custom'],
            'consumed_at_local' => ['present', 'nullable', 'date_format:H:i'],
            'note' => ['present', 'nullable', 'string', 'max:1000'],
            'entries' => ['required', 'array', 'min:1', 'max:100'],
            'entries.*' => ['array:food_item_id,recipe_id,quantity'],
            'entries.*.food_item_id' => ['present', 'nullable', 'integer'],
            'entries.*.recipe_id' => ['present', 'nullable', 'integer'],
            'entries.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
        ];
        if ($creating) {
            $rules['submission_key'] = ['required', 'uuid'];
        }
        $validator = Validator::make($attributes, $rules);
        $validator->after(function ($validator) use ($attributes, $user): void {
            if (isset($attributes['consumed_on'])) {
                $today = CarbonImmutable::now($user->calendarTimezone())->toDateString();
                if ($attributes['consumed_on'] > $today) {
                    $validator->errors()->add('consumed_on', __('messages.nutrition_future_meal'));
                }
            }
            foreach ((array) ($attributes['entries'] ?? []) as $index => $entry) {
                if ((($entry['food_item_id'] ?? null) === null) === (($entry['recipe_id'] ?? null) === null)) {
                    $validator->errors()->add("entries.{$index}", __('messages.nutrition_entry_reference'));
                }
            }
        });

        return $validator->validate();
    }
}
