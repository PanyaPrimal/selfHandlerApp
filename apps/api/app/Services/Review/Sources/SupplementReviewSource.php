<?php

namespace App\Services\Review\Sources;

use App\Contracts\ReviewAggregateSource;
use App\Models\User;
use App\Services\SupplementAdherenceService;

class SupplementReviewSource implements ReviewAggregateSource
{
    public function __construct(private readonly SupplementAdherenceService $adherence) {}

    public function key(): string
    {
        return 'supplements';
    }

    public function daily(User $user, string $date): array
    {
        return $this->adherence->forDay($user, $date);
    }

    public function period(User $user, string $from, string $to): array
    {
        return $this->adherence->forRange($user, $from, $to)['summary'];
    }
}
