<?php

namespace Tests\Feature\Nutrition;

use App\Models\DailyReview;

class NutritionIntegrationTest extends NutritionTestCase
{
    public function test_today_transports_the_same_nutrition_summary_without_review_persistence(): void
    {
        $owner = $this->createUser();
        $this->createMeal($owner);
        $review = DailyReview::create([
            'user_id' => $owner->id, 'review_date' => self::TODAY, 'mood' => 8,
            'notes' => 'Good nutrition', 'completed_at' => now(),
        ]);
        $before = $review->fresh()->getAttributes();
        $this->actingAs($owner);

        $daySummary = $this->getJson('/api/nutrition/days/'.self::TODAY)
            ->assertOk()->json('data.summary');
        $todaySummary = $this->getJson('/api/today?date='.self::TODAY)
            ->assertOk()->json('module_summaries.nutrition');

        $this->assertSame($daySummary, $todaySummary);
        $this->assertSame($before, $review->fresh()->getAttributes());
        $this->assertArrayNotHasKey('nutrition', $review->fresh()->getAttributes());
    }

    public function test_profile_goal_and_workout_remain_authoritative_inputs_with_no_reverse_writes(): void
    {
        $owner = $this->createUser();
        $goal = $this->createMassGoal($owner);
        $program = $this->createWorkoutProgram($owner);
        $profileBefore = $owner->ensureProfile()->getAttributes();
        $goalBefore = $goal->getAttributes();
        $programBefore = $program->getAttributes();
        $this->actingAs($owner);

        $this->putJson('/api/nutrition/settings', [
            'body_goal_id' => $goal->id, 'protein_percent' => 20, 'fat_percent' => 30,
            'carbs_percent' => 50, 'water_override_ml' => null,
        ])->assertOk();
        $this->getJson('/api/nutrition/days/'.self::TODAY)->assertOk();

        $this->assertSame($profileBefore, $owner->ensureProfile()->fresh()->getAttributes());
        $this->assertSame($goalBefore, $goal->fresh()->getAttributes());
        $this->assertSame($programBefore, $program->fresh()->getAttributes());
    }

    public function test_existing_profile_body_workout_and_today_shapes_remain_backward_compatible(): void
    {
        $owner = $this->createUser();
        $program = $this->createWorkoutProgram($owner);
        $this->actingAs($owner);

        $this->getJson('/api/profile')->assertOk()
            ->assertJsonPath('data.bmr_formula', 'mifflin_st_jeor');
        $this->getJson('/api/body/goals')->assertOk()->assertJsonStructure(['data']);
        $this->getJson('/api/workout-programs')->assertOk()
            ->assertJsonPath('data.0.id', $program->id)
            ->assertJsonPath('data.0.planned_energy_kcal', 300);
        $this->getJson('/api/today?date='.self::TODAY)->assertOk()->assertJsonStructure([
            'summary', 'module_summaries' => ['sleep', 'routine_activities', 'workouts', 'nutrition'],
        ]);
    }
}
