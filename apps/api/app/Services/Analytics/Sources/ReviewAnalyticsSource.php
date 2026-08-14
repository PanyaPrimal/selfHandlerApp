<?php

namespace App\Services\Analytics\Sources;

use App\Contracts\AnalyticsMetricSource;
use App\Models\User;
use App\Services\Review\ReviewAnalyticsSeriesService;

class ReviewAnalyticsSource implements AnalyticsMetricSource
{
    public function __construct(private readonly ReviewAnalyticsSeriesService $series) {}

    public function keys(): array
    {
        return ['review.energy', 'review.mood', 'review.stress', 'review.day_rating'];
    }

    public function daily(User $user, string $from, string $to, array $keys): array
    {
        $rows = $this->series->daily($user, $from, $to);
        $result = [];
        foreach ($this->keys() as $key) {
            if (in_array($key, $keys, true)) {
                $result[$key] = $rows[substr($key, strlen('review.'))];
            }
        }

        return $result;
    }
}
