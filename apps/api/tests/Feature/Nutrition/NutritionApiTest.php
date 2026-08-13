<?php

namespace Tests\Feature\Nutrition;

class NutritionApiTest extends NutritionTestCase
{
    public function test_all_feature_routes_require_authentication(): void
    {
        foreach ([
            ['get', '/api/nutrition/foods'], ['post', '/api/nutrition/foods'],
            ['patch', '/api/nutrition/foods/1'], ['get', '/api/nutrition/recipes'],
            ['post', '/api/nutrition/recipes'], ['patch', '/api/nutrition/recipes/1'],
            ['get', '/api/nutrition/settings'], ['put', '/api/nutrition/settings'],
            ['get', '/api/nutrition/days/'.self::TODAY], ['get', '/api/nutrition/summary?from='.self::TODAY.'&to='.self::TODAY],
            ['post', '/api/nutrition/meals'], ['patch', '/api/nutrition/meals/1'],
            ['delete', '/api/nutrition/meals/1'],
        ] as [$method, $uri]) {
            $this->json(strtoupper($method), $uri)->assertUnauthorized();
        }
    }

    public function test_food_and_recipe_lifecycle_operations_are_strict_exact_and_owner_scoped(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $this->actingAs($owner);
        $this->getJson('/api/nutrition/foods')->assertOk()
            ->assertJsonPath('data.0.system_key', 'plain_water');

        $foodId = $this->postJson('/api/nutrition/foods', [
            'name' => 'Oats', 'basis_unit' => 'gram', 'is_beverage' => false,
            'calories_per_100' => 100, 'protein_per_100' => 10, 'fat_per_100' => 2,
            'carbs_per_100' => 20, 'quality_score' => 80, 'hydration_ratio' => 0,
        ])->assertCreated()->assertJsonPath('data.calories_per_100', '100.000')->json('data.id');
        $this->patchJson("/api/nutrition/foods/{$foodId}", [
            'name' => 'Rolled oats', 'is_archived' => true,
        ])->assertOk()->assertJsonPath('data.is_archived', true);
        $this->patchJson('/api/nutrition/foods/'.$this->water()->id, ['name' => 'Mutate'])
            ->assertNotFound();

        $secondId = $this->postJson('/api/nutrition/foods', [
            'name' => 'Nut', 'basis_unit' => 'gram', 'is_beverage' => false,
            'calories_per_100' => 300, 'protein_per_100' => 20, 'fat_per_100' => 10,
            'carbs_per_100' => 40, 'quality_score' => 60, 'hydration_ratio' => 0,
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/nutrition/foods/{$foodId}", ['is_archived' => false])->assertOk();
        $recipeId = $this->postJson('/api/nutrition/recipes', [
            'name' => 'Bowl', 'description' => null, 'components' => [
                ['food_item_id' => $foodId, 'quantity_grams' => 100],
                ['food_item_id' => $secondId, 'quantity_grams' => 300],
            ],
        ])->assertCreated()->assertJsonPath('data.nutrition_per_100.calories', '250.000')->json('data.id');
        $this->getJson('/api/nutrition/recipes')->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson("/api/nutrition/recipes/{$recipeId}", [
            'description' => 'Corrected', 'is_archived' => true,
        ])->assertOk()->assertJsonPath('data.is_archived', true);

        $foreign = $this->createSolid($other, ['name' => 'Foreign']);
        $this->patchJson('/api/nutrition/foods/'.$foreign->id, ['name' => 'Leak'])->assertNotFound();
        $this->postJson('/api/nutrition/foods', [
            'name' => 'Unknown', 'basis_unit' => 'gram', 'is_beverage' => false,
            'calories_per_100' => 0, 'protein_per_100' => 0, 'fat_per_100' => 0,
            'carbs_per_100' => 0, 'quality_score' => null, 'hydration_ratio' => 0,
            'unexpected' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['request']);
    }

    public function test_settings_read_update_and_target_day_operations_expose_exact_states(): void
    {
        $owner = $this->createUser();
        $goal = $this->createMassGoal($owner);
        $this->createWorkoutProgram($owner);
        $this->actingAs($owner);

        $this->getJson('/api/nutrition/settings')->assertOk()
            ->assertJsonPath('data.protein_percent', '20.00')
            ->assertJsonPath('data.body_goal_id', null);
        $this->putJson('/api/nutrition/settings', [
            'body_goal_id' => $goal->id, 'protein_percent' => 20, 'fat_percent' => 30,
            'carbs_percent' => 50, 'water_override_ml' => 2600,
        ])->assertOk()->assertJsonPath('data.water_override_ml', 2600);
        $this->getJson('/api/nutrition/days/'.self::TODAY)->assertOk()
            ->assertJsonPath('data.target.status', 'ready')
            ->assertJsonPath('data.target.planned_workout_kcal', 300)
            ->assertJsonPath('data.target.water_target_ml', 2600)
            ->assertJsonPath('data.summary.meal_count', 0);
        $this->getJson('/api/nutrition/days/not-a-date')->assertUnprocessable();
    }

    public function test_meal_create_update_delete_day_and_range_operations_share_snapshot_totals(): void
    {
        $owner = $this->createUser();
        $food = $this->createSolid($owner);
        $this->actingAs($owner);
        $mealId = $this->postJson('/api/nutrition/meals', [
            'consumed_on' => self::TODAY, 'name' => 'Breakfast', 'category' => 'breakfast',
            'consumed_at_local' => '08:30', 'note' => null,
            'submission_key' => '55555555-5555-4555-8555-555555555555',
            'entries' => [['food_item_id' => $food->id, 'recipe_id' => null, 'quantity' => 200]],
        ])->assertCreated()->assertJsonPath('data.entries.0.calories', '200.000')->json('data.id');

        $this->getJson('/api/nutrition/days/'.self::TODAY)->assertOk()
            ->assertJsonPath('data.summary.calories', '200.000');
        $this->patchJson("/api/nutrition/meals/{$mealId}", [
            'consumed_on' => self::TODAY, 'name' => 'Brunch', 'category' => null,
            'consumed_at_local' => '10:15', 'note' => 'Corrected',
            'entries' => [['food_item_id' => $food->id, 'recipe_id' => null, 'quantity' => 250]],
        ])->assertOk()->assertJsonPath('data.entries.0.calories', '250.000');
        $this->getJson('/api/nutrition/summary?from='.self::YESTERDAY.'&to='.self::TODAY)
            ->assertOk()->assertJsonCount(2, 'data.days')
            ->assertJsonPath('data.days.1.calories', '250.000');
        $this->deleteJson("/api/nutrition/meals/{$mealId}")->assertNoContent();
        $this->assertDatabaseMissing('meals', ['id' => $mealId]);
    }

    public function test_foreign_meal_and_reference_ids_are_indistinguishable_from_missing(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $foreignMeal = $this->createMeal($other);
        $foreignFood = $foreignMeal->entries->first()->foodItem;
        $this->actingAs($owner);

        $this->patchJson('/api/nutrition/meals/'.$foreignMeal->id, [
            'consumed_on' => self::TODAY, 'name' => 'Leak', 'category' => null,
            'consumed_at_local' => null, 'note' => null,
            'entries' => [['food_item_id' => $foreignFood->id, 'recipe_id' => null, 'quantity' => 10]],
        ])->assertNotFound();
        $this->deleteJson('/api/nutrition/meals/'.$foreignMeal->id)->assertNotFound();
        $this->assertDatabaseHas('meals', ['id' => $foreignMeal->id]);
    }
}
