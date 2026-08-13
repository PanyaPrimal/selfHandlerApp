<?php

namespace Tests\Unit\Nutrition;

use App\Services\FoodCatalogueService;
use App\Services\MealService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Nutrition\NutritionTestCase;

class MealServiceTest extends NutritionTestCase
{
    public function test_mixed_atomic_recipe_and_beverage_entries_snapshot_exact_values(): void
    {
        $owner = $this->createUser();
        $solid = $this->createSolid($owner);
        $recipe = $this->createRecipe($owner);
        $drink = $this->createBeverage($owner);
        $water = $this->water();
        $meal = $this->createMeal($owner, ['entries' => [
            ['food_item_id' => $solid->id, 'recipe_id' => null, 'quantity' => 200],
            ['food_item_id' => null, 'recipe_id' => $recipe->id, 'quantity' => 200],
            ['food_item_id' => $drink->id, 'recipe_id' => null, 'quantity' => 250],
            ['food_item_id' => $water->id, 'recipe_id' => null, 'quantity' => 500],
        ]]);

        $entries = $meal->entries;
        $this->assertSame([0, 1, 2, 3], $entries->pluck('sort_order')->all());
        $this->assertSame(['200.000', '500.000', '125.000', '0.000'], $entries->pluck('calories')->all());
        $this->assertSame(['0.000', '0.000', '200.000', '500.000'], $entries->pluck('hydration_ml')->all());
        $this->assertSame('16000.0000', $entries[0]->quality_numerator);
        $this->assertSame('13000.0000', $entries[1]->quality_numerator);
        $this->assertNull($entries[2]->quality_numerator);
    }

    public function test_reference_edits_do_not_drift_history_but_meal_correction_rebuilds_snapshot(): void
    {
        $owner = $this->createUser();
        $food = $this->createSolid($owner);
        $meal = $this->createMeal($owner, ['food' => $food]);
        $before = $meal->entries->first()->only(['reference_name', 'calories', 'protein_grams']);
        app(FoodCatalogueService::class)->update($food, $owner, [
            'name' => 'Corrected grain', 'calories_per_100' => 150, 'protein_per_100' => 15,
        ]);

        $this->assertSame($before, $meal->fresh('entries')->entries->first()->only(array_keys($before)));

        app(MealService::class)->update($meal, $owner, [
            'consumed_on' => self::TODAY, 'name' => 'Breakfast', 'category' => 'breakfast',
            'consumed_at_local' => '08:30', 'note' => null,
            'entries' => [['food_item_id' => $food->id, 'recipe_id' => null, 'quantity' => 200]],
        ]);
        $entry = $meal->fresh('entries')->entries->first();
        $this->assertSame('Corrected grain', $entry->reference_name);
        $this->assertSame('300.000', $entry->calories);
        $this->assertSame('30.000', $entry->protein_grams);
    }

    public function test_duplicate_submission_is_idempotent_and_update_replaces_children_atomically(): void
    {
        $owner = $this->createUser();
        $food = $this->createSolid($owner);
        $payload = [
            'consumed_on' => self::TODAY, 'name' => 'Retry-safe meal', 'category' => null,
            'consumed_at_local' => '10:05', 'note' => null,
            'submission_key' => '22222222-2222-4222-8222-222222222222',
            'entries' => [['food_item_id' => $food->id, 'recipe_id' => null, 'quantity' => 100]],
        ];
        $service = app(MealService::class);

        $first = $service->create($owner, $payload);
        $retry = $service->create($owner, $payload);
        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, $owner->meals()->count());

        $service->update($first, $owner, [
            'consumed_on' => self::TODAY, 'name' => 'Retry-safe meal', 'category' => null,
            'consumed_at_local' => '10:05', 'note' => null,
            'entries' => [['food_item_id' => $food->id, 'recipe_id' => null, 'quantity' => 250]],
        ]);
        $this->assertCount(1, $first->fresh('entries')->entries);
        $this->assertSame('250.000', $first->fresh('entries')->entries->first()->quantity);
    }

    public function test_invalid_reference_xor_future_archived_and_foreign_inputs_leave_no_partial_write(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $mine = $this->createSolid($owner);
        $archived = $this->createSolid($owner, ['name' => 'Archived']);
        app(FoodCatalogueService::class)->update($archived, $owner, ['is_archived' => true]);
        $foreign = $this->createSolid($other, ['name' => 'Foreign']);
        $recipe = $this->createRecipe($owner);

        $cases = [
            [self::TODAY, ['food_item_id' => null, 'recipe_id' => null, 'quantity' => 10]],
            [self::TODAY, ['food_item_id' => $mine->id, 'recipe_id' => $recipe->id, 'quantity' => 10]],
            [self::TODAY, ['food_item_id' => $archived->id, 'recipe_id' => null, 'quantity' => 10]],
            [self::TODAY, ['food_item_id' => $foreign->id, 'recipe_id' => null, 'quantity' => 10]],
            ['2026-08-14', ['food_item_id' => $mine->id, 'recipe_id' => null, 'quantity' => 10]],
        ];

        foreach ($cases as $index => [$date, $entry]) {
            try {
                app(MealService::class)->create($owner, [
                    'consumed_on' => $date, 'name' => 'Invalid', 'category' => 'custom',
                    'consumed_at_local' => null, 'note' => null,
                    'submission_key' => sprintf('33333333-3333-4333-8333-%012d', $index),
                    'entries' => [$entry],
                ]);
                $this->fail('Expected meal validation failure.');
            } catch (ValidationException|ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, $owner->meals()->count());
    }

    public function test_delete_removes_only_owned_meal_and_its_entries(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $meal = $this->createMeal($owner);
        $otherMeal = $this->createMeal($other, [
            'name' => 'Other', 'submission_key' => '44444444-4444-4444-8444-444444444444',
        ]);
        $entryId = $meal->entries->first()->id;

        app(MealService::class)->delete($meal, $owner);
        $this->assertDatabaseMissing('meals', ['id' => $meal->id]);
        $this->assertDatabaseMissing('meal_entries', ['id' => $entryId]);
        $this->assertDatabaseHas('meals', ['id' => $otherMeal->id]);
    }
}
