<?php

namespace App\Services\Analytics\Sources;

use App\Contracts\AnalyticsMetricSource;
use App\Models\User;
use App\Services\BodyAnalyticsSeriesService;

class BodyAnalyticsSource implements AnalyticsMetricSource
{
    public function __construct(private readonly BodyAnalyticsSeriesService $series) {}

    public function keys(): array
    {
        return ['body.body_mass'];
    }

    public function daily(User $user, string $from, string $to, array $keys): array
    {
        return in_array('body.body_mass', $keys, true)
            ? ['body.body_mass' => $this->series->daily($user, $from, $to)] : [];
    }
}
