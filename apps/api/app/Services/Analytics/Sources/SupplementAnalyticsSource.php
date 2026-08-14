<?php

namespace App\Services\Analytics\Sources;

use App\Contracts\AnalyticsMetricSource;
use App\Models\User;
use App\Services\SupplementAnalyticsSeriesService;

class SupplementAnalyticsSource implements AnalyticsMetricSource
{
    public function __construct(private readonly SupplementAnalyticsSeriesService $series) {}

    public function keys(): array
    {
        return ['supplements.adherence'];
    }

    public function daily(User $user, string $from, string $to, array $keys): array
    {
        return in_array('supplements.adherence', $keys, true)
            ? ['supplements.adherence' => $this->series->daily($user, $from, $to)] : [];
    }
}
