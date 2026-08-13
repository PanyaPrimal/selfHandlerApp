<?php

namespace Tests\Unit\Nutrition;

use App\Services\NutritionSummaryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Nutrition\NutritionTestCase;

class NutritionSummaryServiceTest extends NutritionTestCase
{
    public function test_day_totals_hydration_quality_and_progress_come_only_from_snapshots(): void
    {
        $owner = $this->createUser();
        $solid = $this->createSolid($owner);
        $recipe = $this->createRecipe($owner);
        $drink = $this->createBeverage($owner);
        $this->createMeal($owner, ['entries' => [
            ['food_item_id' => $solid->id, 'recipe_id' => null, 'quantity' => 200],
            ['food_item_id' => null, 'recipe_id' => $recipe->id, 'quantity' => 200],
            ['food_item_id' => $drink->id, 'recipe_id' => null, 'quantity' => 250],
            ['food_item_id' => $this->water()->id, 'recipe_id' => null, 'quantity' => 500],
        ]]);

        $day = app(NutritionSummaryService::class)->forDay($owner, self::TODAY);
        $this->assertSame(1, $day['meal_count']);
        $this->assertSame(4, $day['entry_count']);
        $this->assertSame('825.000', $day['calories']);
        $this->assertSame('60.000', $day['protein_grams']);
        $this->assertSame('20.000', $day['fat_grams']);
        $this->assertSame('135.000', $day['carbs_grams']);
        $this->assertSame('700.000', $day['hydration_ml']);
        $this->assertSame('72.50', $day['quality_score']);
        $this->assertSame('42.39', $day['progress']['calories']['percent']);
    }

    public function test_empty_zero_incomplete_and_unavailable_quality_are_distinct(): void
    {
        $owner = $this->createUser(profile: ['weight_grams' => null]);
        $empty = app(NutritionSummaryService::class)->forDay($owner, self::TODAY);

        $this->assertSame(0, $empty['meal_count']);
        $this->assertSame('0.000', $empty['calories']);
        $this->assertNull($empty['quality_score']);
        $this->assertNull($empty['progress']['calories']['target']);
        $this->assertNull($empty['progress']['calories']['percent']);
        $this->assertNull($empty['progress']['quality']['consumed']);

        $this->createMeal($owner, [
            'food' => $this->water(),
            'entries' => [['food_item_id' => $this->water()->id, 'recipe_id' => null, 'quantity' => 100]],
        ]);
        $waterOnly = app(NutritionSummaryService::class)->forDay($owner, self::TODAY);
        $this->assertSame(1, $waterOnly['meal_count']);
        $this->assertSame('0.000', $waterOnly['calories']);
        $this->assertSame('100.000', $waterOnly['hydration_ml']);
        $this->assertNull($waterOnly['quality_score']);
    }

    public function test_range_is_inclusive_ordered_correction_safe_and_rejects_over_366_or_reversed(): void
    {
        $owner = $this->createUser();
        $food = $this->createSolid($owner);
        $this->createMeal($owner, ['food' => $food, 'consumed_on' => self::YESTERDAY]);
        $service = app(NutritionSummaryService::class);
        $range = $service->forRange($owner, self::YESTERDAY, self::TODAY);

        $this->assertSame([self::YESTERDAY, self::TODAY], array_column($range, 'date'));
        $this->assertSame('200.000', $range[0]['calories']);
        $this->assertSame('0.000', $range[1]['calories']);

        foreach ([['2026-08-14', self::TODAY], ['2025-01-01', '2026-08-13']] as [$from, $to]) {
            try {
                $service->forRange($owner, $from, $to);
                $this->fail('Expected invalid bounded range.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_day_and_range_query_counts_remain_bounded_as_entries_grow(): void
    {
        $owner = $this->createUser();
        $food = $this->createSolid($owner);
        $this->createMeal($owner, ['food' => $food]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $service = app(NutritionSummaryService::class);

        $service->forDay($owner, self::TODAY);
        $dayQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $service->forRange($owner, self::YESTERDAY, self::TODAY);
        $rangeQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(10, $dayQueries);
        $this->assertLessThanOrEqual(12, $rangeQueries);
    }
}
