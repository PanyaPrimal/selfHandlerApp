<?php

namespace App\Services\Review\Sources;

use App\Contracts\ReviewAggregateSource;
use App\Models\User;
use App\Services\Finance\FinanceSummaryService;

class FinanceReviewSource implements ReviewAggregateSource
{
    public function __construct(private readonly FinanceSummaryService $summary) {}

    public function key(): string
    {
        return 'finance';
    }

    public function daily(User $user, string $date): array
    {
        return $this->summary->build($user, $date, $date, $date)['actuals'];
    }

    public function period(User $user, string $from, string $to): array
    {
        return $this->summary->build($user, $from, $to, $to)['actuals'];
    }
}
