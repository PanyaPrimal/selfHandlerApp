<?php

namespace Tests\Feature\Nutrition;

use App\Models\FoodItem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

class NutritionSchemaTest extends NutritionTestCase
{
    public function test_additive_tables_columns_water_and_workout_input_exist(): void
    {
        foreach ([
            'food_items' => ['id', 'user_id', 'system_key', 'name', 'basis_unit', 'is_beverage',
                'calories_per_100', 'protein_per_100', 'fat_per_100', 'carbs_per_100', 'quality_score',
                'hydration_ratio', 'is_archived', 'archived_at'],
            'recipes' => ['id', 'user_id', 'name', 'description', 'is_archived', 'archived_at'],
            'recipe_components' => ['id', 'user_id', 'recipe_id', 'food_item_id', 'sort_order', 'quantity_grams'],
            'meals' => ['id', 'user_id', 'consumed_on', 'name', 'category', 'consumed_at_local', 'note', 'submission_key'],
            'meal_entries' => ['id', 'user_id', 'meal_id', 'food_item_id', 'recipe_id', 'sort_order',
                'reference_name', 'basis_unit', 'quantity', 'calories', 'protein_grams', 'fat_grams',
                'carbs_grams', 'hydration_ml', 'quality_numerator', 'quality_denominator'],
            'nutrition_settings' => ['id', 'user_id', 'body_goal_id', 'protein_percent', 'fat_percent',
                'carbs_percent', 'water_override_ml'],
            'nutrition_daily_targets' => ['id', 'user_id', 'target_date', 'status', 'formula', 'bmr_kcal',
                'baseline_kcal', 'goal_adjustment_kcal', 'planned_workout_kcal', 'calorie_target',
                'protein_target_grams', 'fat_target_grams', 'carbs_target_grams', 'water_target_ml',
                'quality_target', 'calculation_basis'],
        ] as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertTrue(Schema::hasColumns($table, $columns), $table);
        }

        $this->assertTrue(Schema::hasColumn('workout_programs', 'planned_energy_kcal'));
        $water = FoodItem::query()->where('system_key', 'plain_water')->sole();
        $this->assertNull($water->user_id);
        $this->assertSame('millilitre', $water->basis_unit);
        $this->assertTrue($water->is_beverage);
        $this->assertSame('0.000', $water->calories_per_100);
        $this->assertSame('1.0000', $water->hydration_ratio);
    }

    public function test_schema_uniques_protect_recipe_meal_settings_and_target_identity(): void
    {
        $owner = $this->createUser();
        $food = $this->createSolid($owner);
        $recipe = $this->createRecipe($owner);
        $meal = $this->createMeal($owner, ['food' => $food]);

        $attempts = [
            fn () => $recipe->components()->create([
                'user_id' => $owner->id, 'food_item_id' => $food->id,
                'sort_order' => 0, 'quantity_grams' => 10,
            ]),
            fn () => $owner->meals()->create([
                'consumed_on' => self::TODAY, 'name' => 'Retry',
                'submission_key' => $meal->submission_key,
            ]),
            fn () => $owner->nutritionSettings()->create([
                'protein_percent' => 20, 'fat_percent' => 30, 'carbs_percent' => 50,
            ]),
        ];
        $owner->nutritionSettings()->create([
            'protein_percent' => 20, 'fat_percent' => 30, 'carbs_percent' => 50,
        ]);

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('Expected unique constraint violation.');
            } catch (UniqueConstraintViolationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_account_deletion_cascades_private_nutrition_but_not_water(): void
    {
        $owner = $this->createUser();
        $food = $this->createSolid($owner);
        $recipe = $this->createRecipe($owner);
        $meal = $this->createMeal($owner, ['food' => $food]);
        $owner->delete();

        $this->assertDatabaseMissing('food_items', ['id' => $food->id]);
        $this->assertDatabaseMissing('recipes', ['id' => $recipe->id]);
        $this->assertDatabaseMissing('meals', ['id' => $meal->id]);
        $this->assertDatabaseHas('food_items', ['system_key' => 'plain_water', 'user_id' => null]);
    }

    public function test_rollback_removes_only_feature_016_and_preserves_prior_rows(): void
    {
        $owner = $this->createUser();
        $goal = $this->createMassGoal($owner);
        $migration = require database_path('migrations/2026_08_13_220000_create_nutrition.php');

        $migration->down();

        $this->assertFalse(Schema::hasTable('food_items'));
        $this->assertFalse(Schema::hasTable('nutrition_daily_targets'));
        $this->assertFalse(Schema::hasColumn('workout_programs', 'planned_energy_kcal'));
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertDatabaseHas('goals', ['id' => $goal->id]);

        $migration->up();
        $this->assertTrue(Schema::hasTable('food_items'));
        $this->assertTrue(Schema::hasColumn('workout_programs', 'planned_energy_kcal'));
    }

    public function test_every_new_index_name_is_mysql_safe(): void
    {
        foreach (['food_items', 'recipes', 'recipe_components', 'meals', 'meal_entries',
            'nutrition_settings', 'nutrition_daily_targets', 'workout_programs'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), $index['name']);
            }
        }
    }
}
