<?php

namespace App\Services;

use App\Models\BodyMeasurement;
use App\Models\User;
use App\ValueObjects\BodyMetric;
use Illuminate\Support\Collection;

/**
 * The deterministic trend of one metric over a bounded window.
 *
 * Entries are manual and sparse, so a rolling average would be undefined for
 * most days and filling the gaps would invent observations the user never made.
 * Instead the slope is ordinary least squares over the observations themselves,
 * with days since the first observation as the independent variable, reported as
 * change per week in the canonical unit.
 *
 * "Not enough information" and "no change" are different answers, so fewer than
 * two observations returns an explicit state rather than a zero slope.
 */
class BodyTrendService
{
    public const PRECISION = 4;

    /**
     * @return array{
     *     metric: string,
     *     state: string,
     *     points: int,
     *     first: array{measured_on: string, value: string}|null,
     *     last: array{measured_on: string, value: string}|null,
     *     change_per_week: string|null
     * }
     */
    public function for(User $user, BodyMetric $metric, string $from, string $to): array
    {
        $observations = BodyMeasurement::query()
            ->ownedBy($user)
            ->where('metric', $metric->value)
            ->whereBetween('measured_on', [$from, $to])
            // Ordering by date makes the result independent of insertion order.
            ->orderBy('measured_on')
            ->get(['measured_on', 'value']);

        $points = $observations->count();

        if ($points === 0) {
            return $this->state($metric, 'empty', 0, null, null, null);
        }

        $first = $observations->first();
        $last = $observations->last();

        $firstPoint = [
            'measured_on' => $first->measured_on->format('Y-m-d'),
            'value' => (string) $first->value,
        ];
        $lastPoint = [
            'measured_on' => $last->measured_on->format('Y-m-d'),
            'value' => (string) $last->value,
        ];

        if ($points === 1) {
            return $this->state($metric, 'insufficient', 1, $firstPoint, $lastPoint, null);
        }

        return $this->state(
            $metric,
            'ready',
            $points,
            $firstPoint,
            $lastPoint,
            $this->changePerWeek($observations, $first->measured_on->format('Y-m-d')),
        );
    }

    /**
     * Least-squares slope in canonical units per day, scaled to a week.
     *
     * @param  Collection<int, BodyMeasurement>  $observations
     */
    private function changePerWeek(Collection $observations, string $origin): ?string
    {
        $originDay = strtotime($origin.' 00:00:00 UTC');
        $count = 0;
        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumXX = 0.0;

        foreach ($observations as $observation) {
            $x = (strtotime($observation->measured_on->format('Y-m-d').' 00:00:00 UTC') - $originDay) / 86400;
            $y = (float) $observation->value;

            $count++;
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumXX += $x * $x;
        }

        $denominator = ($count * $sumXX) - ($sumX * $sumX);

        // Every observation on the same day is impossible (the date is unique per
        // metric), but a defensive guard keeps the service total.
        if ($denominator === 0.0) {
            return null;
        }

        $slopePerDay = (($count * $sumXY) - ($sumX * $sumY)) / $denominator;

        // Rounded once, at the boundary, so nothing accumulates.
        return number_format($slopePerDay * 7, self::PRECISION, '.', '');
    }

    /**
     * @param  array{measured_on: string, value: string}|null  $first
     * @param  array{measured_on: string, value: string}|null  $last
     * @return array{metric: string, state: string, points: int, first: array{measured_on: string, value: string}|null, last: array{measured_on: string, value: string}|null, change_per_week: string|null}
     */
    private function state(
        BodyMetric $metric,
        string $state,
        int $points,
        ?array $first,
        ?array $last,
        ?string $changePerWeek,
    ): array {
        return [
            'metric' => $metric->value,
            'state' => $state,
            'points' => $points,
            'first' => $first,
            'last' => $last,
            'change_per_week' => $changePerWeek,
        ];
    }
}
