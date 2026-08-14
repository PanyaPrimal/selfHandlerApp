<?php

namespace App\Services\Review\Sources;

use App\Contracts\ReviewAggregateSource;
use App\Models\User;
use App\Services\RoutineDayProjectionService;
use App\Services\RoutinePeriodSummaryService;

class RoutineReviewSource implements ReviewAggregateSource
{
    public function __construct(
        private readonly RoutinePeriodSummaryService $summary,
        private readonly RoutineDayProjectionService $days,
    ) {}

    public function key(): string
    {
        return 'routines';
    }

    public function daily(User $user, string $date): array
    {
        return [
            'summary' => $this->summary->summarize($user, $date, $date),
            'routine_activities' => $this->days->project($user, $date)['activity_summary'],
        ];
    }

    public function period(User $user, string $from, string $to): array
    {
        return $this->summary->summarize($user, $from, $to);
    }
}
