<?php

namespace Tests\Feature\Nutrition;

use App\Models\MealEntry;
use App\Models\NutritionDailyTarget;
use App\Models\NutritionSettings;
use App\Models\RecipeComponent;
use RuntimeException;

class NutritionModelTest extends NutritionTestCase
{
    public function test_models_cast_exact_decimals_dates_json_and_boolean_lifecycle_fields(): void
    {
        $owner = $this->createUser();
        $food = $this->createBeverage($owner);
        $meal = $this->createMeal($owner, [
            'food' => $food,
            'entries' => [['food_item_id' => $food->id, 'recipe_id' => null, 'quantity' => 250]],
        ]);
        $entry = $meal->entries->first();

        $this->assertSame('50.000', $food->calories_per_100);
        $this->assertSame('0.8000', $food->hydration_ratio);
        $this->assertTrue($food->is_beverage);
        $this->assertSame(self::TODAY, $meal->consumed_on->format('Y-m-d'));
        $this->assertSame('125.000', $entry->calories);
        $this->assertSame('200.000', $entry->hydration_ml);

        $target = NutritionDailyTarget::create([
            'user_id' => $owner->id, 'target_date' => self::TODAY, 'status' => 'incomplete',
            'formula' => 'mifflin_st_jeor', 'goal_adjustment_kcal' => 0,
            'planned_workout_kcal' => 0, 'quality_target' => 70,
            'calculation_basis' => ['missing_fields' => ['weight_grams']],
        ]);
        $this->assertSame(['missing_fields' => ['weight_grams']], $target->calculation_basis);
    }

    public function test_private_children_reject_a_different_owner(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $recipe = $this->createRecipe($owner);
        $foreignFood = $this->createSolid($other, ['name' => 'Foreign']);

        $this->expectException(RuntimeException::class);
        RecipeComponent::create([
            'user_id' => $other->id, 'recipe_id' => $recipe->id,
            'food_item_id' => $foreignFood->id, 'sort_order' => 9, 'quantity_grams' => 10,
        ]);
    }

    public function test_meal_entry_requires_exactly_one_same_owner_reference_and_matching_basis(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $meal = $this->createMeal($owner);
        $foreign = $this->createSolid($other, ['name' => 'Foreign']);

        foreach ([
            ['food_item_id' => null, 'recipe_id' => null, 'basis_unit' => 'gram'],
            ['food_item_id' => $foreign->id, 'recipe_id' => null, 'basis_unit' => 'gram'],
            ['food_item_id' => $this->water()->id, 'recipe_id' => null, 'basis_unit' => 'gram'],
        ] as $overrides) {
            try {
                MealEntry::create([
                    'user_id' => $owner->id, 'meal_id' => $meal->id, 'sort_order' => random_int(10, 10000),
                    'reference_name' => 'Invalid', 'quantity' => 1, 'calories' => 0,
                    'protein_grams' => 0, 'fat_grams' => 0, 'carbs_grams' => 0,
                    'hydration_ml' => 0, 'quality_denominator' => 0, ...$overrides,
                ]);
                $this->fail('Expected invalid meal child.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_daily_targets_are_immutable_after_insert(): void
    {
        $owner = $this->createUser();
        $target = NutritionDailyTarget::create([
            'user_id' => $owner->id, 'target_date' => self::TODAY, 'status' => 'ready',
            'formula' => 'mifflin_st_jeor', 'goal_adjustment_kcal' => 0,
            'planned_workout_kcal' => 0, 'calorie_target' => 2000, 'quality_target' => 70,
            'calculation_basis' => ['missing_fields' => []],
        ]);

        $this->expectException(RuntimeException::class);
        $target->update(['calorie_target' => 2100]);
    }

    public function test_public_water_target_and_accepted_entry_immutability_cover_destructive_paths(): void
    {
        $owner = $this->createUser();
        $meal = $this->createMeal($owner);

        foreach ([
            fn () => $this->water()->delete(),
            fn () => $meal->entries->first()->update(['calories' => 999]),
            fn () => NutritionDailyTarget::create([
                'user_id' => $owner->id, 'target_date' => self::TODAY, 'status' => 'incomplete',
                'formula' => 'mifflin_st_jeor', 'goal_adjustment_kcal' => 0,
                'planned_workout_kcal' => 0, 'quality_target' => 70, 'calculation_basis' => [],
            ])->delete(),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Expected immutable Nutrition state to reject mutation.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_settings_model_rejects_a_foreign_goal_reference(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $foreignGoal = $this->createMassGoal($other);

        $this->expectException(RuntimeException::class);
        NutritionSettings::create([
            'user_id' => $owner->id, 'body_goal_id' => $foreignGoal->id,
            'protein_percent' => 20, 'fat_percent' => 30, 'carbs_percent' => 50,
        ]);
    }
}
