<?php

namespace Tests\Feature\Nutrition;

use App\Services\NutritionTargetService;

class NutritionUserTargetsTest extends NutritionTestCase
{
    public function test_manual_grams_and_explicit_recalculation_preserve_meals_and_other_users(): void
    {
        $owner = $this->createUser(profile: ['height_meters' => null]);
        $other = $this->createUser('another@example.test');
        $targets = app(NutritionTargetService::class);
        $before = $targets->forDate($owner, self::TODAY);
        $otherBefore = $targets->forDate($other, self::TODAY)->getAttributes();
        $meal = $this->createMeal($owner);
        $entriesBefore = $meal->entries()->get()->toArray();
        $this->actingAs($owner)->putJson('/api/nutrition/settings', [
            'body_goal_id' => null, 'protein_percent' => 20, 'fat_percent' => 30,
            'carbs_percent' => 50, 'water_override_ml' => 2000,
            'macro_targets_grams' => ['protein' => 35, 'fat' => 70, 'carbs' => 210],
        ])->assertOk()->assertJsonPath('data.macro_targets_grams.fat', 70);
        $owner->profile()->update(['height_meters' => 1.70]);
        $this->assertSame('incomplete', $targets->forDate($owner->fresh(), self::TODAY)->status);
        $this->postJson('/api/nutrition/days/'.self::TODAY.'/recalculate')->assertOk()
            ->assertJsonPath('data.status', 'ready')->assertJsonPath('data.protein_target_grams', '35.00')
            ->assertJsonPath('data.fat_target_grams', '70.00')->assertJsonPath('data.carbs_target_grams', '210.00');
        $after = $targets->forDate($owner->fresh(), self::TODAY);
        $this->assertSame($before->id, $after->id);
        $this->assertArrayHasKey('recalculated_at', $after->calculation_basis);
        $this->assertSame($entriesBefore, $meal->entries()->get()->toArray());
        $this->assertSame($otherBefore, $targets->forDate($other, self::TODAY)->getAttributes());
        $this->postJson('/api/nutrition/days/not-a-day/recalculate')->assertUnprocessable();
    }

    public function test_manual_macros_require_all_three_values_and_null_restores_percentages(): void
    {
        $owner = $this->createUser();
        $base = ['body_goal_id' => null, 'protein_percent' => 20, 'fat_percent' => 30,
            'carbs_percent' => 50, 'water_override_ml' => null];
        $this->actingAs($owner)->putJson('/api/nutrition/settings', [...$base,
            'macro_targets_grams' => ['protein' => 20],
        ])->assertUnprocessable();
        $this->putJson('/api/nutrition/settings', [...$base, 'macro_targets_grams' => []])
            ->assertUnprocessable();
        $this->putJson('/api/nutrition/settings', [...$base,
            'macro_targets_grams' => ['protein' => 35, 'fat' => -1, 'carbs' => 210],
        ])->assertUnprocessable();
        $this->putJson('/api/nutrition/settings', [...$base,
            'macro_targets_grams' => ['protein' => 35, 'fat' => 70, 'carbs' => 210],
        ])->assertOk();
        $this->putJson('/api/nutrition/settings', $base)->assertOk()
            ->assertJsonPath('data.macro_targets_grams.fat', 70);
        $this->putJson('/api/nutrition/settings', [...$base, 'macro_targets_grams' => null])
            ->assertOk()->assertJsonPath('data.macro_targets_grams', null);
        $target = app(NutritionTargetService::class)->forDate($owner, self::TODAY);
        $this->assertNotSame('35.00', $target->protein_target_grams);
    }
}
