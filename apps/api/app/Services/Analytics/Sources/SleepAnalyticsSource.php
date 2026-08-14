<?php

namespace App\Services\Analytics\Sources;

use App\Contracts\AnalyticsMetricSource;
use App\Models\User;
use App\Services\SleepAnalyticsSeriesService;

class SleepAnalyticsSource implements AnalyticsMetricSource
{
    public function __construct(private readonly SleepAnalyticsSeriesService $series) {}

    public function keys(): array
    {
        return ['sleep.duration_minutes', 'sleep.quality'];
    }

    public function daily(User $user, string $from, string $to, array $keys): array
    {
        $rows = $this->series->daily($user, $from, $to);
        $result = [];
        if (in_array('sleep.duration_minutes', $keys, true)) {
            $result['sleep.duration_minutes'] = $rows['duration'];
        }
        if (in_array('sleep.quality', $keys, true)) {
            $result['sleep.quality'] = $rows['quality'];
        }

        return $result;
    }
}
