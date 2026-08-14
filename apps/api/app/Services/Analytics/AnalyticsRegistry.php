<?php

namespace App\Services\Analytics;

use App\Contracts\AnalyticsMetricSource;
use App\Models\User;
use App\Services\Analytics\Sources\BodyAnalyticsSource;
use App\Services\Analytics\Sources\FinanceAnalyticsSource;
use App\Services\Analytics\Sources\HabitAnalyticsSource;
use App\Services\Analytics\Sources\NutritionAnalyticsSource;
use App\Services\Analytics\Sources\PlannerAnalyticsSource;
use App\Services\Analytics\Sources\ReviewAnalyticsSource;
use App\Services\Analytics\Sources\RoutineAnalyticsSource;
use App\Services\Analytics\Sources\SleepAnalyticsSource;
use App\Services\Analytics\Sources\SupplementAnalyticsSource;
use App\Services\Analytics\Sources\WorkoutAnalyticsSource;
use LogicException;

class AnalyticsRegistry
{
    /** @var list<AnalyticsMetricSource> */
    private array $sources;

    public function __construct(
        RoutineAnalyticsSource $routines,
        SleepAnalyticsSource $sleep,
        WorkoutAnalyticsSource $workouts,
        NutritionAnalyticsSource $nutrition,
        SupplementAnalyticsSource $supplements,
        HabitAnalyticsSource $habits,
        PlannerAnalyticsSource $planner,
        FinanceAnalyticsSource $finance,
        ReviewAnalyticsSource $review,
        BodyAnalyticsSource $body,
    ) {
        $this->sources = [
            $routines, $sleep, $workouts, $nutrition, $supplements,
            $habits, $planner, $finance, $review, $body,
        ];
        $keys = array_merge(...array_map(fn (AnalyticsMetricSource $source): array => $source->keys(), $this->sources));
        if (count($keys) !== count(array_unique($keys))) {
            throw new LogicException('Analytics source keys must be unique.');
        }
    }

    /**
     * @param  list<string>  $keys
     * @return array<string,list<array<string,mixed>>>
     */
    public function daily(User $user, string $from, string $to, array $keys): array
    {
        $requested = array_values(array_unique($keys));
        $result = [];
        foreach ($this->sources as $source) {
            $sourceKeys = array_values(array_intersect($requested, $source->keys()));
            if ($sourceKeys !== []) {
                $result += $source->daily($user, $from, $to, $sourceKeys);
            }
        }
        foreach ($requested as $key) {
            if (! array_key_exists($key, $result)) {
                throw new LogicException("No Analytics source returned [$key].");
            }
        }

        return $result;
    }
}
