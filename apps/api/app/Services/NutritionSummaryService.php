<?php

namespace App\Services;

use App\Models\Meal;
use App\Models\MealEntry;
use App\Models\NutritionDailyTarget;
use App\Models\User;
use App\Support\NutritionDecimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class NutritionSummaryService
{
    public function __construct(private readonly NutritionTargetService $targets) {}

    /** @return array<string, mixed> */
    public function forDay(User $user, string $date): array
    {
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
        $target = $this->targets->forDate($user, $date);
        $mealCount = Meal::query()->ownedBy($user)->whereDate('consumed_on', $date)->count();
        $row = $this->aggregateQuery($user, $date, $date)->first();

        return $this->present($date, $mealCount, $row, $target);
    }

    /** @return list<array<string, mixed>> */
    public function forRange(User $user, string $from, string $to): array
    {
        $validator = Validator::make(['from' => $from, 'to' => $to], [
            'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d'],
        ]);
        $validator->after(function ($validator) use ($from, $to): void {
            if ($from > $to || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 365) {
                $validator->errors()->add('to', __('messages.nutrition_range_invalid'));
            }
        });
        $validator->validate();

        $aggregates = $this->aggregateQuery($user, $from, $to)->keyBy('date');
        $mealCounts = Meal::query()->ownedBy($user)->whereBetween('consumed_on', [$from, $to])
            ->selectRaw('consumed_on as date, COUNT(*) as meal_count')->groupBy('consumed_on')
            ->get()->keyBy(fn ($row) => (string) $row->date);
        $targets = NutritionDailyTarget::query()->ownedBy($user)->whereBetween('target_date', [$from, $to])
            ->get()->keyBy(fn ($target) => $target->target_date->toDateString());

        return collect(CarbonPeriod::create($from, $to))->map(function ($day) use ($aggregates, $mealCounts, $targets): array {
            $date = $day->format('Y-m-d');

            return $this->present(
                $date,
                (int) ($mealCounts->get($date)?->meal_count ?? 0),
                $aggregates->get($date),
                $targets->get($date),
            );
        })->values()->all();
    }

    private function aggregateQuery(User $user, string $from, string $to): Collection
    {
        return MealEntry::query()
            ->join('meals', 'meals.id', '=', 'meal_entries.meal_id')
            ->where('meal_entries.user_id', $user->id)
            ->whereBetween('meals.consumed_on', [$from, $to])
            ->selectRaw('meals.consumed_on as date, COUNT(meal_entries.id) as entry_count,
                COALESCE(SUM(meal_entries.calories), 0) as calories,
                COALESCE(SUM(meal_entries.protein_grams), 0) as protein_grams,
                COALESCE(SUM(meal_entries.fat_grams), 0) as fat_grams,
                COALESCE(SUM(meal_entries.carbs_grams), 0) as carbs_grams,
                COALESCE(SUM(meal_entries.hydration_ml), 0) as hydration_ml,
                COALESCE(SUM(meal_entries.quality_numerator), 0) as quality_numerator,
                COALESCE(SUM(meal_entries.quality_denominator), 0) as quality_denominator')
            ->groupBy('meals.consumed_on')->orderBy('meals.consumed_on')->get();
    }

    /** @return array<string, mixed> */
    private function present(string $date, int $mealCount, mixed $row, ?NutritionDailyTarget $target): array
    {
        $values = [
            'calories' => NutritionDecimal::format($row?->calories ?? 0, 3),
            'protein_grams' => NutritionDecimal::format($row?->protein_grams ?? 0, 3),
            'fat_grams' => NutritionDecimal::format($row?->fat_grams ?? 0, 3),
            'carbs_grams' => NutritionDecimal::format($row?->carbs_grams ?? 0, 3),
            'hydration_ml' => NutritionDecimal::format($row?->hydration_ml ?? 0, 3),
        ];
        $qualityDenominator = NutritionDecimal::format($row?->quality_denominator ?? 0, 3);
        $quality = bccomp($qualityDenominator, '0', 3) > 0
            ? NutritionDecimal::divide($row?->quality_numerator ?? 0, $qualityDenominator, 2) : null;

        return [
            'date' => $date,
            'meal_count' => $mealCount,
            'entry_count' => (int) ($row?->entry_count ?? 0),
            'calories' => $values['calories'],
            'protein_grams' => $values['protein_grams'],
            'fat_grams' => $values['fat_grams'],
            'carbs_grams' => $values['carbs_grams'],
            'hydration_ml' => $values['hydration_ml'],
            'quality_score' => $quality,
            'progress' => [
                'calories' => $this->progress($values['calories'], $target?->calorie_target),
                'protein' => $this->progress($values['protein_grams'], $target?->protein_target_grams),
                'fat' => $this->progress($values['fat_grams'], $target?->fat_target_grams),
                'carbs' => $this->progress($values['carbs_grams'], $target?->carbs_target_grams),
                'hydration' => $this->progress($values['hydration_ml'], $target?->water_target_ml),
                'quality' => [
                    'consumed' => $quality,
                    'target' => NutritionDecimal::format($target?->quality_target ?? 70, 2),
                    'percent' => $quality === null ? null
                        : NutritionDecimal::multiply(
                            NutritionDecimal::divide($quality, $target?->quality_target ?? 70, 4), 100, 2),
                ],
            ],
        ];
    }

    /** @return array{consumed:string,target:?string,percent:?string} */
    private function progress(string $consumed, mixed $target): array
    {
        $numericTarget = $target === null ? null : NutritionDecimal::format($target, 2);

        return [
            'consumed' => $consumed,
            'target' => $numericTarget,
            'percent' => $numericTarget === null || bccomp($numericTarget, '0', 2) <= 0 ? null
                : NutritionDecimal::multiply(NutritionDecimal::divide($consumed, $numericTarget, 4), 100, 2),
        ];
    }
}
