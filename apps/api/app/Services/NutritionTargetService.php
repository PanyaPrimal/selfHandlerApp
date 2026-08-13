<?php

namespace App\Services;

use App\Models\Goal;
use App\Models\NutritionDailyTarget;
use App\Models\NutritionSettings;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\User;
use App\Models\WorkoutProgram;
use App\Models\WorkoutSession;
use App\ValueObjects\BodyMetric;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class NutritionTargetService
{
    private const ACTIVITY_COEFFICIENTS = [
        'sedentary' => 1.20,
        'light' => 1.30,
        'moderate' => 1.40,
        'high' => 1.50,
    ];

    public function __construct(private readonly NutritionSettingsService $settings) {}

    public function forDate(User $user, string $date): NutritionDailyTarget
    {
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
        $existing = NutritionDailyTarget::query()->ownedBy($user)->whereDate('target_date', $date)->first();
        if ($existing) {
            return $existing;
        }
        $attributes = $this->calculate($user, $date);

        try {
            return DB::transaction(function () use ($user, $date, $attributes): NutritionDailyTarget {
                $target = NutritionDailyTarget::create([
                    'user_id' => $user->id, 'target_date' => $date, ...$attributes,
                ]);

                return $target->fresh();
            });
        } catch (QueryException $exception) {
            $winner = NutritionDailyTarget::query()->ownedBy($user)->whereDate('target_date', $date)->first();
            if (! $winner) {
                throw $exception;
            }

            return $winner;
        }
    }

    /** @return array<string, mixed> */
    public function refinement(User $user, NutritionDailyTarget $target): array
    {
        abort_unless((int) $target->user_id === (int) $user->id, 404);
        $sessions = WorkoutSession::query()->ownedBy($user)
            ->whereDate('performed_on', $target->target_date)
            ->where('outcome', WorkoutSession::OUTCOME_COMPLETED)
            ->with('enduranceDetail')->get();
        $known = $sessions->filter(fn ($session) => $session->enduranceDetail?->energy_kcal !== null);
        $actual = (int) $known->sum(fn ($session) => $session->enduranceDetail->energy_kcal);
        $missing = $sessions->count() - $known->count();
        $status = match (true) {
            $target->calorie_target === null => 'incomplete_target',
            $sessions->isEmpty() => 'no_completed_workouts',
            $missing > 0 => 'missing_energy',
            default => 'available',
        };

        return [
            'status' => $status,
            'reference_calorie_target' => $target->calorie_target,
            'planned_workout_kcal' => $target->planned_workout_kcal,
            'actual_workout_kcal' => $actual,
            'refined_calorie_target' => $target->calorie_target === null ? null
                : max(0, $target->calorie_target - $target->planned_workout_kcal + $actual),
            'missing_actual_energy_count' => $missing,
        ];
    }

    /** @return array<string, mixed> */
    private function calculate(User $user, string $date): array
    {
        $profile = $user->profile()->firstOrFail();
        $settings = $this->settings->get($user);
        $missing = $profile->missingCalculationFields();
        $formula = $profile->bmr_formula;
        $activity = self::ACTIVITY_COEFFICIENTS[$profile->baseline_activity] ?? null;
        $profileInputs = [
            'weight_kg' => $profile->weight_grams === null ? null : number_format($profile->weight_grams / 1000, 3, '.', ''),
            'height_cm' => $profile->height_meters === null ? null : number_format((float) $profile->height_meters * 100, 2, '.', ''),
            'age_years' => $profile->date_of_birth === null ? null : (int) floor(
                CarbonImmutable::parse($profile->date_of_birth, $profile->timezone)
                    ->diffInYears(CarbonImmutable::parse($date, $profile->timezone)),
            ),
            'sex' => $profile->sex,
            'body_fat_percent' => $profile->body_fat_percentage,
        ];
        [$occurrenceIds, $plannedEnergy, $missingPlannedEnergy, $plannedDuration] = $this->plannedInputs($user, $date);
        [$goalBasis, $goalAdjustment, $goalLimitations] = $this->goalAdjustment($user, $settings, $date);
        [$waterTarget, $waterRule] = $this->waterTarget($settings, $profile->weight_grams, $plannedDuration);
        $limitations = ['target_is_product_estimate', ...$goalLimitations];
        if ($missingPlannedEnergy > 0) {
            $limitations[] = 'planned_workout_energy_missing';
        }

        $bmr = null;
        $baseline = null;
        $calories = null;
        $protein = null;
        $fat = null;
        $carbs = null;
        if ($missing === [] && $activity !== null) {
            $weightKg = $profile->weight_grams / 1000;
            if ($formula === 'katch_mcardle') {
                $leanMass = $weightKg * (1 - ((float) $profile->body_fat_percentage / 100));
                $bmr = 370 + (21.6 * $leanMass);
            } else {
                $sexOffset = $profile->sex === 'male' ? 5 : -161;
                $bmr = (10 * $weightKg) + (6.25 * ((float) $profile->height_meters * 100))
                    - (5 * $profileInputs['age_years']) + $sexOffset;
            }
            $baseline = $bmr * $activity;
            $candidate = (int) round($baseline + $goalAdjustment + $plannedEnergy);
            $floor = (int) round($bmr);
            $calories = max($floor, $candidate);
            if ($calories !== $candidate) {
                $limitations[] = 'calorie_target_floored_at_bmr';
            }
            $protein = $calories * ((float) $settings->protein_percent / 100) / 4;
            $fat = $calories * ((float) $settings->fat_percent / 100) / 9;
            $carbs = $calories * ((float) $settings->carbs_percent / 100) / 4;
        }

        return [
            'status' => $missing === [] && $activity !== null ? 'ready' : 'incomplete',
            'formula' => $formula,
            'bmr_kcal' => $bmr === null ? null : number_format($bmr, 2, '.', ''),
            'baseline_kcal' => $baseline === null ? null : number_format($baseline, 2, '.', ''),
            'goal_adjustment_kcal' => $goalAdjustment,
            'planned_workout_kcal' => $plannedEnergy,
            'calorie_target' => $calories,
            'protein_target_grams' => $protein === null ? null : number_format($protein, 2, '.', ''),
            'fat_target_grams' => $fat === null ? null : number_format($fat, 2, '.', ''),
            'carbs_target_grams' => $carbs === null ? null : number_format($carbs, 2, '.', ''),
            'water_target_ml' => $waterTarget,
            'quality_target' => 70,
            'calculation_basis' => [
                'missing_fields' => $missing,
                'profile_updated_at' => $profile->updated_at?->toISOString(),
                'profile_inputs' => $profileInputs,
                'activity_coefficient' => $activity === null ? null : number_format($activity, 3, '.', ''),
                'settings' => $this->settingsArray($settings),
                'goal' => $goalBasis,
                'planned_occurrence_ids' => $occurrenceIds,
                'planned_energy_missing_count' => $missingPlannedEnergy,
                'water_rule' => $waterRule,
                'limitation_codes' => array_values(array_unique($limitations)),
            ],
        ];
    }

    /** @return array{list<int>,int,int,int} */
    private function plannedInputs(User $user, string $date): array
    {
        $occurrences = PlannedOccurrence::query()->ownedBy($user)
            ->whereHas('recurringRule', fn ($query) => $query->where('owner_type', RecurringRule::OWNER_WORKOUT_PROGRAM))
            ->where(function ($query) use ($date): void {
                $query->where(function ($original) use ($date): void {
                    $original->whereDate('occurrence_date', $date)->whereNull('rescheduled_to');
                })->orWhereDate('rescheduled_to', $date);
            })->with('recurringRule')->get();
        $programs = WorkoutProgram::query()->ownedBy($user)
            ->whereIn('id', $occurrences->pluck('recurringRule.owner_id')->filter())
            ->where('is_active', true)->where('is_archived', false)->get()->keyBy('id');
        $accepted = $occurrences->filter(fn ($occurrence) => $programs->has($occurrence->recurringRule->owner_id));
        $energy = 0;
        $missing = 0;
        $duration = 0;
        foreach ($accepted as $occurrence) {
            $program = $programs[$occurrence->recurringRule->owner_id];
            $duration += (int) ($program->planned_duration_seconds ?? 0);
            if ($program->planned_energy_kcal === null) {
                $missing++;
            } else {
                $energy += (int) $program->planned_energy_kcal;
            }
        }

        return [$accepted->pluck('id')->map(fn ($id) => (int) $id)->values()->all(), $energy, $missing, $duration];
    }

    /** @return array{array<string,mixed>,int,list<string>} */
    private function goalAdjustment(User $user, NutritionSettings $settings, string $date): array
    {
        $empty = [
            'id' => null, 'start_weight_kg' => null, 'target_weight_kg' => null, 'deadline' => null,
            'raw_adjustment_kcal' => null, 'applied_adjustment_kcal' => 0, 'status_code' => 'not_selected',
        ];
        if ($settings->body_goal_id === null) {
            return [$empty, 0, []];
        }
        $goal = Goal::query()->ownedBy($user)->whereKey($settings->body_goal_id)
            ->where('type', Goal::TYPE_BODY)->where('status', 'active')->where('is_archived', false)
            ->with('bodyDetail')->first();
        if (! $goal || $goal->bodyDetail?->metric !== BodyMetric::BodyMass) {
            return [[...$empty, 'id' => $settings->body_goal_id, 'status_code' => 'unavailable'], 0, []];
        }
        $detail = $goal->bodyDetail;
        $start = (float) $detail->starting_value / 1000;
        $target = (float) $detail->target_value / 1000;
        $deadline = $goal->target_date?->toDateString();
        $basis = [
            'id' => $goal->id, 'start_weight_kg' => number_format($start, 3, '.', ''),
            'target_weight_kg' => number_format($target, 3, '.', ''), 'deadline' => $deadline,
            'raw_adjustment_kcal' => null, 'applied_adjustment_kcal' => 0, 'status_code' => 'ready',
        ];
        if ($deadline === null || $deadline <= $date) {
            return [[...$basis, 'status_code' => 'deadline_unavailable'], 0, []];
        }
        $directionValid = match ($detail->direction) {
            'lose' => $target < $start,
            'gain' => $target > $start,
            'maintain' => abs($target - $start) < 0.0001,
            default => false,
        };
        if (! $directionValid) {
            return [[...$basis, 'status_code' => 'direction_mismatch'], 0, []];
        }
        $days = CarbonImmutable::parse($date)->diffInDays(CarbonImmutable::parse($deadline));
        $raw = (($target - $start) * 7700) / $days;
        $applied = max(-1000, min(1000, (int) round($raw)));
        $limitations = ['goal_energy_density_approximation'];
        if ($applied !== (int) round($raw)) {
            $limitations[] = 'goal_adjustment_capped';
        }

        return [[
            ...$basis,
            'raw_adjustment_kcal' => number_format($raw, 3, '.', ''),
            'applied_adjustment_kcal' => $applied,
        ], $applied, $limitations];
    }

    /** @return array{?int,array<string,mixed>} */
    private function waterTarget(NutritionSettings $settings, ?int $weightGrams, int $plannedDuration): array
    {
        if ($settings->water_override_ml !== null) {
            return [$settings->water_override_ml, [
                'source' => 'override', 'base_ml' => null, 'planned_duration_seconds' => $plannedDuration,
                'workout_addition_ml' => 0, 'applied_ml' => $settings->water_override_ml,
            ]];
        }
        if ($weightGrams === null) {
            return [null, [
                'source' => 'unavailable', 'base_ml' => null, 'planned_duration_seconds' => $plannedDuration,
                'workout_addition_ml' => 0, 'applied_ml' => null,
            ]];
        }
        $base = (int) round(max(1500, min(4000, ($weightGrams / 1000) * 30)));
        $addition = (int) round(($plannedDuration / 3600) * 350);
        $applied = min(5000, $base + $addition);

        return [$applied, [
            'source' => 'estimate', 'base_ml' => $base, 'planned_duration_seconds' => $plannedDuration,
            'workout_addition_ml' => $addition, 'applied_ml' => $applied,
        ]];
    }

    /** @return array<string, mixed> */
    private function settingsArray(NutritionSettings $settings): array
    {
        return [
            'body_goal_id' => $settings->body_goal_id,
            'protein_percent' => $settings->protein_percent,
            'fat_percent' => $settings->fat_percent,
            'carbs_percent' => $settings->carbs_percent,
            'water_override_ml' => $settings->water_override_ml,
        ];
    }
}
