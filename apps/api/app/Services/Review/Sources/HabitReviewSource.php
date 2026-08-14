<?php

namespace App\Services\Review\Sources;

use App\Contracts\ReviewAggregateSource;
use App\Models\User;
use App\Services\HabitPeriodSummaryService;

class HabitReviewSource implements ReviewAggregateSource
{
    public function __construct(private readonly HabitPeriodSummaryService $summary) {}

    public function key(): string
    {
        return 'habits';
    }

    public function daily(User $user, string $date): array
    {
        return $this->summary->summarize($user, $date, $date);
    }

    public function period(User $user, string $from, string $to): array
    {
        return $this->summary->summarize($user, $from, $to);
    }
}
