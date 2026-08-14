<?php

namespace App\Services\Analytics;

use InvalidArgumentException;

class AnalyticsCatalog
{
    public const DEFAULT_METRIC = 'sleep.duration_minutes';

    /** @var list<array<string,mixed>> */
    private const METRICS = [
        ['key' => 'routines.completion_rate', 'module' => 'routines', 'unit' => 'percent', 'operator' => 'percentage', 'precision' => 2, 'empty_is_zero' => false, 'sensitivity' => 'standard'],
        ['key' => 'sleep.duration_minutes', 'module' => 'sleep', 'unit' => 'minutes', 'operator' => 'mean', 'precision' => 2, 'empty_is_zero' => false, 'sensitivity' => 'health'],
        ['key' => 'sleep.quality', 'module' => 'sleep', 'unit' => 'rating_5', 'operator' => 'mean', 'precision' => 2, 'empty_is_zero' => false, 'sensitivity' => 'health'],
        ['key' => 'workouts.completed_sessions', 'module' => 'workouts', 'unit' => 'count', 'operator' => 'sum', 'precision' => 0, 'empty_is_zero' => true, 'sensitivity' => 'health'],
        ['key' => 'workouts.duration_minutes', 'module' => 'workouts', 'unit' => 'minutes', 'operator' => 'sum', 'precision' => 2, 'empty_is_zero' => true, 'sensitivity' => 'health'],
        ['key' => 'nutrition.calorie_target_adherence', 'module' => 'nutrition', 'unit' => 'percent', 'operator' => 'mean', 'precision' => 2, 'empty_is_zero' => false, 'sensitivity' => 'health'],
        ['key' => 'supplements.adherence', 'module' => 'supplements', 'unit' => 'percent', 'operator' => 'percentage', 'precision' => 2, 'empty_is_zero' => false, 'sensitivity' => 'health'],
        ['key' => 'habits.completion_rate', 'module' => 'habits', 'unit' => 'percent', 'operator' => 'percentage', 'precision' => 2, 'empty_is_zero' => false, 'sensitivity' => 'standard'],
        ['key' => 'planner.completion_rate', 'module' => 'planner', 'unit' => 'percent', 'operator' => 'percentage', 'precision' => 2, 'empty_is_zero' => false, 'sensitivity' => 'standard'],
        ['key' => 'finance.income', 'module' => 'finance', 'unit' => 'currency', 'operator' => 'sum', 'precision' => 4, 'empty_is_zero' => true, 'sensitivity' => 'finance'],
        ['key' => 'finance.expense', 'module' => 'finance', 'unit' => 'currency', 'operator' => 'sum', 'precision' => 4, 'empty_is_zero' => true, 'sensitivity' => 'finance'],
        ['key' => 'finance.net', 'module' => 'finance', 'unit' => 'currency', 'operator' => 'sum', 'precision' => 4, 'empty_is_zero' => true, 'sensitivity' => 'finance'],
        ['key' => 'review.energy', 'module' => 'review', 'unit' => 'rating_10', 'operator' => 'mean', 'precision' => 2, 'empty_is_zero' => false, 'sensitivity' => 'well_being'],
        ['key' => 'review.mood', 'module' => 'review', 'unit' => 'rating_10', 'operator' => 'mean', 'precision' => 2, 'empty_is_zero' => false, 'sensitivity' => 'well_being'],
        ['key' => 'review.stress', 'module' => 'review', 'unit' => 'rating_10', 'operator' => 'mean', 'precision' => 2, 'empty_is_zero' => false, 'sensitivity' => 'well_being'],
        ['key' => 'review.day_rating', 'module' => 'review', 'unit' => 'rating_10', 'operator' => 'mean', 'precision' => 2, 'empty_is_zero' => false, 'sensitivity' => 'well_being'],
        ['key' => 'body.body_mass', 'module' => 'body', 'unit' => 'kilograms', 'operator' => 'last', 'precision' => 4, 'empty_is_zero' => false, 'sensitivity' => 'health'],
    ];

    /** @var list<array{key:string,left_metric:string,right_metric:string,minimum_samples:int}> */
    private const CORRELATIONS = [
        ['key' => 'sleep_energy', 'left_metric' => 'sleep.duration_minutes', 'right_metric' => 'review.energy', 'minimum_samples' => 7],
        ['key' => 'sleep_quality_mood', 'left_metric' => 'sleep.quality', 'right_metric' => 'review.mood', 'minimum_samples' => 7],
        ['key' => 'habit_completion_day_rating', 'left_metric' => 'habits.completion_rate', 'right_metric' => 'review.day_rating', 'minimum_samples' => 7],
    ];

    /** @return list<array<string,mixed>> */
    public function metrics(): array
    {
        return self::METRICS;
    }

    /** @return list<array{key:string,left_metric:string,right_metric:string,minimum_samples:int}> */
    public function correlations(): array
    {
        return self::CORRELATIONS;
    }

    /** @return array{daily_days:int,weekly_days:int,monthly_days:int,correlation_days:int} */
    public function limits(): array
    {
        return ['daily_days' => 93, 'weekly_days' => 730, 'monthly_days' => 3653, 'correlation_days' => 366];
    }

    /** @return array<string,mixed> */
    public function definition(string $key): array
    {
        foreach (self::METRICS as $definition) {
            if ($definition['key'] === $key) {
                return $definition;
            }
        }

        throw new InvalidArgumentException("Unknown Analytics metric [$key].");
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_column(self::METRICS, 'key');
    }
}
