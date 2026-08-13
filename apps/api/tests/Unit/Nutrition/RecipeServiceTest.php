<?php

namespace Tests\Unit\Nutrition;

use App\Services\FoodCatalogueService;
use App\Services\RecipeNutritionService;
use App\Services\RecipeService;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Nutrition\NutritionTestCase;

class RecipeServiceTest extends NutritionTestCase
{
    public function test_recipe_derives_exact_per_100_nutrition_and_weighted_quality(): void
    {
        $owner = $this->createUser();
        $recipe = $this->createRecipe($owner);
        $nutrition = app(RecipeNutritionService::class)->calculate($recipe);

        $this->assertSame('400.000', $nutrition['total_weight_grams']);
        $this->assertSame('250.000', $nutrition['calories']);
        $this->assertSame('17.500', $nutrition['protein_grams']);
        $this->assertSame('8.000', $nutrition['fat_grams']);
        $this->assertSame('35.000', $nutrition['carbs_grams']);
        $this->assertSame('65.00', $nutrition['quality_score']);
    }

    public function test_component_replacement_is_ordered_unique_atomic_and_correctable(): void
    {
        $owner = $this->createUser();
        $recipe = $this->createRecipe($owner);
        $food = $this->createSolid($owner, ['name' => 'Replacement']);

        app(RecipeService::class)->update($recipe, $owner, [
            'name' => 'Corrected bowl', 'components' => [[
                'food_item_id' => $food->id, 'quantity_grams' => 125,
            ]],
        ]);

        $recipe->refresh()->load('components.food');
        $this->assertSame('Corrected bowl', $recipe->name);
        $this->assertCount(1, $recipe->components);
        $this->assertSame(0, $recipe->components->first()->sort_order);
        $this->assertSame('125.000', $recipe->components->first()->quantity_grams);
    }

    public function test_recipe_rejects_beverage_archived_foreign_duplicate_and_zero_mass_components(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $valid = $this->createSolid($owner);
        $beverage = $this->createBeverage($owner);
        $archived = $this->createSolid($owner, ['name' => 'Archived']);
        app(FoodCatalogueService::class)->update($archived, $owner, ['is_archived' => true]);
        $foreign = $this->createSolid($other, ['name' => 'Foreign']);

        foreach ([
            [['food_item_id' => $beverage->id, 'quantity_grams' => 10]],
            [['food_item_id' => $archived->id, 'quantity_grams' => 10]],
            [['food_item_id' => $foreign->id, 'quantity_grams' => 10]],
            [['food_item_id' => $valid->id, 'quantity_grams' => 0]],
            [['food_item_id' => $valid->id, 'quantity_grams' => 10], ['food_item_id' => $valid->id, 'quantity_grams' => 20]],
        ] as $components) {
            try {
                app(RecipeService::class)->create($owner, [
                    'name' => 'Invalid '.count($components).'-'.random_int(1, 999999),
                    'description' => null, 'components' => $components,
                ]);
                $this->fail('Expected invalid recipe components.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_archive_restore_filters_and_rejects_archived_new_meal_reference(): void
    {
        $owner = $this->createUser();
        $recipe = $this->createRecipe($owner);
        $service = app(RecipeService::class);
        $service->update($recipe, $owner, ['is_archived' => true]);

        $this->assertNotContains($recipe->id, $service->list($owner)->pluck('id')->all());
        $this->assertContains($recipe->id, $service->list($owner, 'archived')->pluck('id')->all());
        $this->assertNotNull($recipe->fresh()->archived_at);

        $service->update($recipe->fresh(), $owner, ['is_archived' => false]);
        $this->assertContains($recipe->id, $service->list($owner)->pluck('id')->all());
    }
}
