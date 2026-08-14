<?php

namespace App\Services;

use App\Models\BodyMeasurement;
use App\Models\User;
use App\ValueObjects\BodyMetric;

class BodyAnalyticsSeriesService
{
    /** @return list<array<string,mixed>> */
    public function daily(User $user, string $from, string $to): array
    {
        return BodyMeasurement::query()->ownedBy($user)
            ->where('metric', BodyMetric::BodyMass->value)->whereBetween('measured_on', [$from, $to])
            ->orderBy('measured_on')->get(['measured_on', 'value'])
            ->map(fn (BodyMeasurement $measurement): array => [
                'date' => $measurement->measured_on->format('Y-m-d'),
                'numerator' => bcdiv((string) $measurement->value, '1000', 8),
                'denominator' => null, 'sample_count' => 1, 'complete' => true, 'reasons' => [],
            ])->all();
    }
}
