<?php

namespace App\Services\Analytics\Sources;

use App\Contracts\AnalyticsMetricSource;
use App\Models\User;
use App\Services\WorkoutAnalyticsSeriesService;

class WorkoutAnalyticsSource implements AnalyticsMetricSource
{
    public function __construct(private readonly WorkoutAnalyticsSeriesService $series) {}

    public function keys(): array
    {
        return ['workouts.completed_sessions', 'workouts.duration_minutes'];
    }

    public function daily(User $user, string $from, string $to, array $keys): array
    {
        $rows = $this->series->daily($user, $from, $to);
        $mapping = ['workouts.completed_sessions' => 'completed', 'workouts.duration_minutes' => 'duration'];

        return $this->pick($rows, $mapping, $keys);
    }

    private function pick(array $rows, array $mapping, array $keys): array
    {
        $result = [];
        foreach ($mapping as $key => $sourceKey) {
            if (in_array($key, $keys, true)) {
                $result[$key] = $rows[$sourceKey];
            }
        }

        return $result;
    }
}
