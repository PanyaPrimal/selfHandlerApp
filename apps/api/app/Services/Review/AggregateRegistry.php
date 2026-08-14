<?php

namespace App\Services\Review;

use App\Contracts\ReviewAggregateSource;
use App\Models\User;
use App\Services\Review\Sources\FinanceReviewSource;
use App\Services\Review\Sources\HabitReviewSource;
use App\Services\Review\Sources\NutritionReviewSource;
use App\Services\Review\Sources\PlannerReviewSource;
use App\Services\Review\Sources\RoutineReviewSource;
use App\Services\Review\Sources\SleepReviewSource;
use App\Services\Review\Sources\SupplementReviewSource;
use App\Services\Review\Sources\WorkoutReviewSource;
use LogicException;

class AggregateRegistry
{
    /** @var list<ReviewAggregateSource> */
    private array $sources;

    public function __construct(
        RoutineReviewSource $routines,
        SleepReviewSource $sleep,
        WorkoutReviewSource $workouts,
        NutritionReviewSource $nutrition,
        SupplementReviewSource $supplements,
        HabitReviewSource $habits,
        PlannerReviewSource $planner,
        FinanceReviewSource $finance,
    ) {
        $this->sources = [$routines, $sleep, $workouts, $nutrition, $supplements, $habits, $planner, $finance];
        if (count(array_unique($this->keys())) !== count($this->sources)) {
            throw new LogicException('Review aggregate source keys must be unique.');
        }
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(fn (ReviewAggregateSource $source): string => $source->key(), $this->sources);
    }

    /** @return array<string,array<string,mixed>> */
    public function daily(User $user, string $date): array
    {
        $raw = [];
        foreach ($this->sources as $source) {
            $raw[$source->key()] = $source->daily($user, $date);
        }
        $routines = $raw['routines'];

        return [
            'routines' => $routines['summary'],
            'routine_activities' => $routines['routine_activities'],
            'sleep' => $raw['sleep'],
            'workouts' => $raw['workouts'],
            'nutrition' => $raw['nutrition'],
            'supplements' => $raw['supplements'],
            'habits' => $raw['habits'],
            'planner' => $raw['planner'],
            'finance' => $raw['finance'],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public function period(User $user, string $from, string $to): array
    {
        $modules = [];
        foreach ($this->sources as $source) {
            $modules[$source->key()] = $source->period($user, $from, $to);
        }

        return $modules;
    }
}
