<?php

namespace Tests\Feature\Attachments;

use App\Models\FoodItem;
use App\Models\MealEntry;
use App\Services\Attachments\AttachmentService;
use App\Services\NutritionSummaryService;
use Illuminate\Support\Facades\DB;
use Tests\Support\AttachmentTestCase;

class MealAttachmentConsumerTest extends AttachmentTestCase
{
    public function test_meal_reads_add_attachments_without_changing_snapshots_or_summary(): void
    {
        $owner = $this->user();
        $owner->ensureProfile()->update(['timezone' => 'UTC']);
        $meal = $this->meal($owner);
        $water = FoodItem::query()->where('system_key', 'plain_water')->firstOrFail();
        MealEntry::query()->create([
            'user_id' => $owner->id, 'meal_id' => $meal->id, 'food_item_id' => $water->id,
            'recipe_id' => null, 'sort_order' => 0, 'reference_name' => 'Snapshot',
            'basis_unit' => 'millilitre', 'quantity' => '100.000', 'calories' => '250.000',
            'protein_grams' => '20.000', 'fat_grams' => '10.000', 'carbs_grams' => '30.000',
            'hydration_ml' => '0.000', 'quality_numerator' => '8000.0000',
            'quality_denominator' => '100.000',
        ]);
        $beforeEntry = DB::table('meal_entries')->where('meal_id', $meal->id)->first();
        $summaryBefore = app(NutritionSummaryService::class)->forDay($owner, '2026-08-13');
        app(AttachmentService::class)->upload($owner, 'meal', $meal->id, 'meal-one', $this->image('meal.webp'));
        $this->actingAs($owner);

        $this->getJson('/api/nutrition/days/2026-08-13')->assertOk()
            ->assertJsonCount(1, 'data.meals.0.attachments')
            ->assertJsonPath('data.summary.calories', '250.000');
        $this->assertEquals($beforeEntry, DB::table('meal_entries')->where('meal_id', $meal->id)->first());
        $this->assertSame($summaryBefore, app(NutritionSummaryService::class)->forDay($owner, '2026-08-13'));
    }

    public function test_attachment_projection_has_a_fixed_query_budget_for_twenty_meals(): void
    {
        $owner = $this->user();
        $owner->ensureProfile()->update(['timezone' => 'UTC']);
        foreach (range(1, 20) as $index) {
            $meal = $this->meal($owner, ['submission_key' => sprintf('00000000-0000-4000-8000-%012d', $index)]);
            app(AttachmentService::class)->upload($owner, 'meal', $meal->id, "meal-{$index}", $this->image());
        }
        $this->actingAs($owner);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/nutrition/days/2026-08-13')->assertOk()->assertJsonCount(20, 'data.meals');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(16, $count);
    }
}
