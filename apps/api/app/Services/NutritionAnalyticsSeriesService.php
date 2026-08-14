<?php

namespace App\Services;

use App\Models\MealEntry;
use App\Models\NutritionDailyTarget;
use App\Models\User;

class NutritionAnalyticsSeriesService
{
    /** @return list<array<string,mixed>> */
    public function daily(User $user, string $from, string $to): array
    {
        $calories = MealEntry::query()
            ->join('meals', 'meals.id', '=', 'meal_entries.meal_id')
            ->where('meal_entries.user_id', $user->id)
            ->whereBetween('meals.consumed_on', [$from, $to])
            ->selectRaw('meals.consumed_on AS date, COALESCE(SUM(meal_entries.calories), 0) AS calories')
            ->groupBy('meals.consumed_on')->get()->mapWithKeys(
                fn ($row): array => [(string) $row->date => (string) $row->calories],
            );
        $targets = NutritionDailyTarget::query()->ownedBy($user)
            ->whereBetween('target_date', [$from, $to])->orderBy('target_date')->get(['target_date', 'calorie_target']);

        return $targets->filter(fn (NutritionDailyTarget $target): bool => (int) $target->calorie_target > 0)
            ->map(function (NutritionDailyTarget $target) use ($calories): array {
                $date = $target->target_date->format('Y-m-d');
                $percent = bcmul(bcdiv($calories->get($date, '0'), (string) $target->calorie_target, 10), '100', 10);
                $distance = bcsub($percent, '100', 10);
                if (bccomp($distance, '0', 10) < 0) {
                    $distance = bcsub('0', $distance, 10);
                }
                $closeness = bcsub('100', $distance, 10);
                if (bccomp($closeness, '0', 10) < 0) {
                    $closeness = '0';
                }

                return [
                    'date' => $date, 'numerator' => $closeness, 'denominator' => '1',
                    'sample_count' => 1, 'complete' => true, 'reasons' => [],
                ];
            })->values()->all();
    }
}
