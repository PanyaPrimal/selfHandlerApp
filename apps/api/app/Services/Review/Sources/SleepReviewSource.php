<?php

namespace App\Services\Review\Sources;

use App\Contracts\ReviewAggregateSource;
use App\Models\User;
use App\Services\SleepStatisticsService;

class SleepReviewSource implements ReviewAggregateSource
{
    public function __construct(private readonly SleepStatisticsService $statistics) {}

    public function key(): string
    {
        return 'sleep';
    }

    public function daily(User $user, string $date): array
    {
        return $this->statistics->summarize($user, $date, $date, $date);
    }

    public function period(User $user, string $from, string $to): array
    {
        return $this->statistics->summarize($user, $from, $to);
    }
}
