<?php

namespace App\Services\Analytics;

class TrendService
{
    /** @param list<array<string,mixed>> $points
     * @return array<string,mixed>
     */
    public function summarize(array $points, int $precision): array
    {
        $available = [];
        foreach ($points as $index => $point) {
            if (($point['state'] ?? null) === 'ready' && $point['value'] !== null) {
                $available[] = ['x' => $index, 'value' => (float) $point['value'], 'raw' => $point['value']];
            }
        }
        $count = count($available);
        $state = $count === 0 ? 'empty' : ($count === 1 ? 'insufficient' : 'ready');
        $first = $count > 0 ? $available[0]['raw'] : null;
        $last = $count > 0 ? $available[$count - 1]['raw'] : null;

        return [
            'state' => $state,
            'available_points' => $count,
            'total_buckets' => count($points),
            'first' => $first,
            'last' => $last,
            'delta' => $count < 2 ? null : $this->format($available[$count - 1]['value'] - $available[0]['value'], $precision),
            'slope_per_bucket' => $count < 2 ? null : $this->format($this->slope($available), $precision),
        ];
    }

    /** @return array{absolute_delta:?string,percentage_delta:?string,percentage_delta_reason:string} */
    public function compare(array $current, array $previous, int $precision): array
    {
        if ($current['value'] === null || $previous['value'] === null) {
            return ['absolute_delta' => null, 'percentage_delta' => null, 'percentage_delta_reason' => 'missing_value'];
        }
        $delta = bcsub((string) $current['value'], (string) $previous['value'], 12);
        if (bccomp((string) $previous['value'], '0', 12) === 0) {
            return [
                'absolute_delta' => bcround($delta, $precision),
                'percentage_delta' => null,
                'percentage_delta_reason' => 'previous_zero',
            ];
        }

        return [
            'absolute_delta' => bcround($delta, $precision),
            'percentage_delta' => bcround(bcmul(bcdiv($delta, (string) $previous['value'], 12), '100', 12), 2),
            'percentage_delta_reason' => 'available',
        ];
    }

    /** @param list<array{x:int,value:float,raw:string}> $points */
    private function slope(array $points): float
    {
        $count = count($points);
        $sumX = $sumY = $sumXY = $sumXX = 0.0;
        foreach ($points as $point) {
            $sumX += $point['x'];
            $sumY += $point['value'];
            $sumXY += $point['x'] * $point['value'];
            $sumXX += $point['x'] * $point['x'];
        }

        return (($count * $sumXY) - ($sumX * $sumY)) / (($count * $sumXX) - ($sumX * $sumX));
    }

    private function format(float $value, int $precision): string
    {
        return number_format(round($value, $precision), $precision, '.', '');
    }
}
