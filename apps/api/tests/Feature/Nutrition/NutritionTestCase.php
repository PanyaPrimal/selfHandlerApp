<?php

namespace Tests\Feature\Nutrition;

use App\Models\BodyGoalDetail;
use App\Models\FoodItem;
use App\Models\Goal;
use App\Models\Meal;
use App\Models\Recipe;
use App\Models\User;
use App\Models\WorkoutProgram;
use App\Services\FoodCatalogueService;
use App\Services\MealService;
use App\Services\RecipeService;
use App\Services\WorkoutProgramRecurrence;
use App\ValueObjects\BodyMetric;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class NutritionTestCase extends TestCase
{
    use RefreshDatabase;

    protected const TODAY = '2026-08-13';

    protected const YESTERDAY = '2026-08-12';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(self::TODAY.' 09:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** @param array<string, mixed> $profile */
    protected function createUser(
        string $email = 'owner@example.test',
        string $timezone = 'UTC',
        array $profile = [],
    ): User {
        $user = User::factory()->create(['email' => $email, 'email_verified_at' => null]);
        $user->ensureProfile()->update([
            'timezone' => $timezone,
            'bmr_formula' => 'mifflin_st_jeor',
            'date_of_birth' => '1990-01-01',
            'sex' => 'female',
            'height_meters' => 1.65,
            'weight_grams' => 70000,
            'body_fat_percentage' => 25,
            'baseline_activity' => 'moderate',
            ...$profile,
        ]);
        $user->unsetRelation('profile');

        return $user->fresh();
    }

    protected function water(): FoodItem
    {
        return FoodItem::query()->where('system_key', 'plain_water')->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    protected function createSolid(User $user, array $attributes = []): FoodItem
    {
        return app(FoodCatalogueService::class)->create($user, [
            'name' => 'Grain',
            'basis_unit' => 'gram',
            'is_beverage' => false,
            'calories_per_100' => 100,
            'protein_per_100' => 10,
            'fat_per_100' => 2,
            'carbs_per_100' => 20,
            'quality_score' => 80,
            'hydration_ratio' => 0,
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function createBeverage(User $user, array $attributes = []): FoodItem
    {
        return app(FoodCatalogueService::class)->create($user, [
            'name' => 'Recovery drink',
            'basis_unit' => 'millilitre',
            'is_beverage' => true,
            'calories_per_100' => 50,
            'protein_per_100' => 2,
            'fat_per_100' => 0,
            'carbs_per_100' => 10,
            'quality_score' => null,
            'hydration_ratio' => 0.8,
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function createRecipe(User $user, array $attributes = []): Recipe
    {
        $first = $attributes['first'] ?? $this->createSolid($user, ['name' => 'Recipe grain']);
        $second = $attributes['second'] ?? $this->createSolid($user, [
            'name' => 'Recipe nut', 'calories_per_100' => 300, 'protein_per_100' => 20,
            'fat_per_100' => 10, 'carbs_per_100' => 40, 'quality_score' => 60,
        ]);

        return app(RecipeService::class)->create($user, [
            'name' => $attributes['name'] ?? 'Grain bowl',
            'description' => $attributes['description'] ?? null,
            'components' => [
                ['food_item_id' => $first->id, 'quantity_grams' => 100],
                ['food_item_id' => $second->id, 'quantity_grams' => 300],
            ],
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function createMeal(User $user, array $attributes = []): Meal
    {
        $food = $attributes['food'] ?? null;
        if (! array_key_exists('entries', $attributes) && $food === null) {
            $food = $this->createSolid($user);
        }

        return app(MealService::class)->create($user, [
            'consumed_on' => $attributes['consumed_on'] ?? self::TODAY,
            'name' => $attributes['name'] ?? 'Breakfast',
            'category' => $attributes['category'] ?? 'breakfast',
            'consumed_at_local' => $attributes['consumed_at_local'] ?? '08:30',
            'note' => $attributes['note'] ?? null,
            'submission_key' => $attributes['submission_key'] ?? '11111111-1111-4111-8111-111111111111',
            'entries' => $attributes['entries'] ?? [[
                'food_item_id' => $food->id, 'recipe_id' => null, 'quantity' => 200,
            ]],
        ]);
    }

    protected function createMassGoal(
        User $user,
        int $targetGrams = 68000,
        string $targetDate = '2026-09-12',
    ): Goal {
        $goal = Goal::create([
            'user_id' => $user->id, 'name' => 'Body mass target', 'type' => Goal::TYPE_BODY,
            'status' => 'active', 'target_date' => $targetDate,
        ]);
        BodyGoalDetail::create([
            'user_id' => $user->id, 'goal_id' => $goal->id,
            'metric' => BodyMetric::BodyMass, 'direction' => 'lose',
            'starting_value' => 70000, 'target_value' => $targetGrams,
        ]);

        return $goal->fresh('bodyDetail');
    }

    /** @param array<string, mixed> $attributes */
    protected function createWorkoutProgram(User $user, array $attributes = []): WorkoutProgram
    {
        $program = WorkoutProgram::create([
            'user_id' => $user->id, 'name' => 'Planned cardio', 'workout_type' => 'cardio',
            'intensity' => 'moderate', 'planned_duration_seconds' => 3600,
            'planned_energy_kcal' => 300, ...$attributes,
        ]);

        app(WorkoutProgramRecurrence::class)->apply($program, $user, [
            'schedule_type' => 'daily', 'starts_on' => self::TODAY,
        ], []);

        return $program->fresh('recurringRule.ruleWeekdays');
    }
}
