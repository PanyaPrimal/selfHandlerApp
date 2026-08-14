<?php

namespace App\Services\Review\Sources;

use App\Contracts\ReviewAggregateSource;
use App\Models\User;
use App\Services\WorkoutStatisticsService;

class WorkoutReviewSource implements ReviewAggregateSource
{
    public function __construct(private readonly WorkoutStatisticsService $statistics) {}

    public function key(): string
    {
        return 'workouts';
    }

    public function daily(User $user, string $date): array
    {
        return $this->statistics->forRange($user, $date, $date)['summary'];
    }

    public function period(User $user, string $from, string $to): array
    {
        return $this->statistics->forRange($user, $from, $to)['summary'];
    }
}
