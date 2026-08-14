<?php

namespace App\Services\Analytics\Sources;

use App\Contracts\AnalyticsMetricSource;
use App\Models\User;
use App\Services\RoutineAnalyticsSeriesService;

class RoutineAnalyticsSource implements AnalyticsMetricSource
{
    public function __construct(private readonly RoutineAnalyticsSeriesService $series) {}

    public function keys(): array
    {
        return ['routines.completion_rate'];
    }

    public function daily(User $user, string $from, string $to, array $keys): array
    {
        return in_array('routines.completion_rate', $keys, true)
            ? ['routines.completion_rate' => $this->series->daily($user, $from, $to)] : [];
    }
}
