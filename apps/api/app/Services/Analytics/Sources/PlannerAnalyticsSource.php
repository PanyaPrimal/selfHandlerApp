<?php

namespace App\Services\Analytics\Sources;

use App\Contracts\AnalyticsMetricSource;
use App\Models\User;
use App\Services\Planner\PlannerAnalyticsSeriesService;

class PlannerAnalyticsSource implements AnalyticsMetricSource
{
    public function __construct(private readonly PlannerAnalyticsSeriesService $series) {}

    public function keys(): array
    {
        return ['planner.completion_rate'];
    }

    public function daily(User $user, string $from, string $to, array $keys): array
    {
        return in_array('planner.completion_rate', $keys, true)
            ? ['planner.completion_rate' => $this->series->daily($user, $from, $to)] : [];
    }
}
