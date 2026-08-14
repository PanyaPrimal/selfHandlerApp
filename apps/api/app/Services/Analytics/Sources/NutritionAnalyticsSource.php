<?php

namespace App\Services\Analytics\Sources;

use App\Contracts\AnalyticsMetricSource;
use App\Models\User;
use App\Services\NutritionAnalyticsSeriesService;

class NutritionAnalyticsSource implements AnalyticsMetricSource
{
    public function __construct(private readonly NutritionAnalyticsSeriesService $series) {}

    public function keys(): array
    {
        return ['nutrition.calorie_target_adherence'];
    }

    public function daily(User $user, string $from, string $to, array $keys): array
    {
        return in_array('nutrition.calorie_target_adherence', $keys, true)
            ? ['nutrition.calorie_target_adherence' => $this->series->daily($user, $from, $to)] : [];
    }
}
