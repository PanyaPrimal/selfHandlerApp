<?php

namespace Tests\Unit\Nutrition;

use App\Models\NutritionDailyTarget;
use App\Services\NutritionSettingsService;
use App\Services\NutritionTargetService;
use App\Services\WorkoutSessionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Nutrition\NutritionTestCase;

class NutritionTargetServiceTest extends NutritionTestCase
{
    public function test_mifflin_target_uses_non_sport_activity_planned_energy_macros_and_water_exactly(): void
    {
        $owner = $this->createUser();
        $program = $this->createWorkoutProgram($owner);
        $target = app(NutritionTargetService::class)->forDate($owner, self::TODAY);

        $this->assertSame('ready', $target->status);
        $this->assertSame('1390.25', $target->bmr_kcal);
        $this->assertSame('1946.35', $target->baseline_kcal);
        $this->assertSame(300, $target->planned_workout_kcal);
        $this->assertSame(2246, $target->calorie_target);
        $this->assertSame('112.30', $target->protein_target_grams);
        $this->assertSame('74.87', $target->fat_target_grams);
        $this->assertSame('280.75', $target->carbs_target_grams);
        $this->assertSame(2450, $target->water_target_ml);
        $this->assertSame([$program->recurringRule->occurrences()->whereDate('occurrence_date', self::TODAY)->value('id')],
            $target->calculation_basis['planned_occurrence_ids']);
        $this->assertSame('1.400', $target->calculation_basis['activity_coefficient']);
    }

    public function test_katch_fixture_and_incomplete_profiles_do_not_invent_targets(): void
    {
        $katch = $this->createUser(profile: [
            'bmr_formula' => 'katch_mcardle', 'body_fat_percentage' => 25,
            'baseline_activity' => 'light', 'date_of_birth' => null,
            'sex' => 'unspecified', 'height_meters' => null,
        ]);
        $ready = app(NutritionTargetService::class)->forDate($katch, self::TODAY);
        $this->assertSame('1504.00', $ready->bmr_kcal);
        $this->assertSame('1955.20', $ready->baseline_kcal);
        $this->assertSame(1955, $ready->calorie_target);

        $incomplete = $this->createUser('incomplete@example.test', profile: [
            'bmr_formula' => 'katch_mcardle', 'body_fat_percentage' => null,
        ]);
        $missing = app(NutritionTargetService::class)->forDate($incomplete, self::TODAY);
        $this->assertSame('incomplete', $missing->status);
        $this->assertNull($missing->bmr_kcal);
        $this->assertNull($missing->calorie_target);
        $this->assertNull($missing->protein_target_grams);
        $this->assertContains('body_fat_percentage', $missing->calculation_basis['missing_fields']);
    }

    public function test_selected_mass_goal_applies_transparent_7700_adjustment_cap_and_bmr_floor(): void
    {
        $owner = $this->createUser();
        $goal = $this->createMassGoal($owner);
        app(NutritionSettingsService::class)->update($owner, [
            'body_goal_id' => $goal->id, 'protein_percent' => 20,
            'fat_percent' => 30, 'carbs_percent' => 50, 'water_override_ml' => null,
        ]);
        $target = app(NutritionTargetService::class)->forDate($owner, self::TODAY);

        $this->assertSame(-513, $target->goal_adjustment_kcal);
        $this->assertSame(1433, $target->calorie_target);
        $this->assertSame('-513.333', $target->calculation_basis['goal']['raw_adjustment_kcal']);
        $this->assertContains('goal_energy_density_approximation', $target->calculation_basis['limitation_codes']);

        $fast = $this->createUser('fast@example.test');
        $fastGoal = $this->createMassGoal($fast, 20000, '2026-08-14');
        app(NutritionSettingsService::class)->update($fast, [
            'body_goal_id' => $fastGoal->id, 'protein_percent' => 20,
            'fat_percent' => 30, 'carbs_percent' => 50, 'water_override_ml' => null,
        ]);
        $floored = app(NutritionTargetService::class)->forDate($fast, self::TODAY);
        $this->assertSame(-1000, $floored->goal_adjustment_kcal);
        $this->assertSame(1390, $floored->calorie_target);
        $this->assertContains('goal_adjustment_capped', $floored->calculation_basis['limitation_codes']);
        $this->assertContains('calorie_target_floored_at_bmr', $floored->calculation_basis['limitation_codes']);
    }

    public function test_settings_validate_amdr_sum_owned_active_mass_goal_and_water_override(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $foreignGoal = $this->createMassGoal($other);
        $service = app(NutritionSettingsService::class);

        foreach ([
            ['body_goal_id' => null, 'protein_percent' => 35, 'fat_percent' => 35, 'carbs_percent' => 45, 'water_override_ml' => null],
            ['body_goal_id' => $foreignGoal->id, 'protein_percent' => 20, 'fat_percent' => 30, 'carbs_percent' => 50, 'water_override_ml' => null],
            ['body_goal_id' => null, 'protein_percent' => 20, 'fat_percent' => 30, 'carbs_percent' => 50, 'water_override_ml' => 999],
        ] as $invalid) {
            try {
                $service->update($owner, $invalid);
                $this->fail('Expected invalid settings.');
            } catch (ModelNotFoundException|ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_target_materializes_once_and_never_drifts_when_inputs_change(): void
    {
        $owner = $this->createUser();
        $program = $this->createWorkoutProgram($owner);
        $service = app(NutritionTargetService::class);
        $first = $service->forDate($owner, self::TODAY);
        $attributes = $first->getAttributes();

        $owner->ensureProfile()->update(['weight_grams' => 90000, 'baseline_activity' => 'high']);
        $program->update(['planned_energy_kcal' => 900]);
        $this->createMeal($owner);
        $second = $service->forDate($owner->fresh(), self::TODAY);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($attributes, $second->getAttributes());
        $this->assertSame(1, NutritionDailyTarget::query()
            ->where('user_id', $owner->id)->whereDate('target_date', self::TODAY)->count());
    }

    public function test_refinement_replaces_only_planned_energy_and_reports_missing_actual_energy(): void
    {
        $owner = $this->createUser();
        $this->createWorkoutProgram($owner);
        $target = app(NutritionTargetService::class)->forDate($owner, self::TODAY);
        app(WorkoutSessionService::class)->createManual($owner, [
            'name' => 'Run', 'workout_type' => 'cardio', 'performed_on' => self::TODAY,
            'duration_seconds' => 1800, 'started_time' => null, 'note' => null,
            'endurance' => ['activity' => 'running', 'run_type' => 'easy', 'distance_m' => 5000,
                'average_heart_rate' => null, 'energy_kcal' => 450],
        ]);
        $service = app(NutritionTargetService::class);
        $refinement = $service->refinement($owner, $target);

        $this->assertSame('available', $refinement['status']);
        $this->assertSame(2246, $refinement['reference_calorie_target']);
        $this->assertSame(300, $refinement['planned_workout_kcal']);
        $this->assertSame(450, $refinement['actual_workout_kcal']);
        $this->assertSame(2396, $refinement['refined_calorie_target']);
        $this->assertSame(0, $refinement['missing_actual_energy_count']);

        app(WorkoutSessionService::class)->createManual($owner, [
            'name' => 'Walk', 'workout_type' => 'cardio', 'performed_on' => self::TODAY,
            'duration_seconds' => 1200,
            'endurance' => ['activity' => 'cycling', 'run_type' => null, 'distance_m' => null,
                'average_heart_rate' => null, 'energy_kcal' => null],
        ]);
        $missing = $service->refinement($owner, $target);
        $this->assertSame('missing_energy', $missing['status']);
        $this->assertSame(1, $missing['missing_actual_energy_count']);
        $this->assertSame($attributes = $target->getAttributes(), $target->fresh()->getAttributes());
    }
}
