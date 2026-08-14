<?php

namespace App\Services\Analytics\Sources;

use App\Contracts\AnalyticsMetricSource;
use App\Models\User;
use App\Services\HabitAnalyticsSeriesService;

class HabitAnalyticsSource implements AnalyticsMetricSource
{
    public function __construct(private readonly HabitAnalyticsSeriesService $series) {}

    public function keys(): array
    {
        return ['habits.completion_rate'];
    }

    public function daily(User $user, string $from, string $to, array $keys): array
    {
        return in_array('habits.completion_rate', $keys, true)
            ? ['habits.completion_rate' => $this->series->daily($user, $from, $to)] : [];
    }
}
