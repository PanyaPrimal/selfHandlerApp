<?php

namespace App\Services\Analytics;

class CorrelationService
{
    /**
     * @param  array<string,mixed>  $definition
     * @param  list<array<string,mixed>>  $leftPoints
     * @param  list<array<string,mixed>>  $rightPoints
     * @return array<string,mixed>
     */
    public function finding(
        array $definition,
        array $leftPoints,
        array $rightPoints,
        string $from,
        string $to,
    ): array {
        $left = $this->byDate($leftPoints);
        $right = $this->byDate($rightPoints);
        $dates = array_values(array_intersect(array_keys($left), array_keys($right)));
        sort($dates);
        $pairs = array_map(fn (string $date): array => [$left[$date], $right[$date]], $dates);
        $minimum = (int) $definition['minimum_samples'];
        if (count($pairs) < $minimum) {
            return $this->unavailable($definition, $from, $to, count($pairs), 'insufficient_samples');
        }

        $leftValues = array_column($pairs, 0);
        $rightValues = array_column($pairs, 1);
        $coefficient = $this->pearson($leftValues, $rightValues);
        if ($coefficient === null) {
            return $this->unavailable($definition, $from, $to, count($pairs), 'zero_variance');
        }
        $rounded = round($coefficient, 4);
        $classification = $this->classify($rounded);

        return [
            ...$definition,
            'from' => $from,
            'to' => $to,
            'state' => 'ready',
            'coefficient' => number_format($rounded, 4, '.', ''),
            ...$classification,
            'sample_count' => count($pairs),
            'reason' => null,
        ];
    }

    /** @return array{direction:string,strength:string} */
    public function classify(float $coefficient): array
    {
        $absolute = abs($coefficient);
        if ($absolute < 0.1) {
            return ['direction' => 'none', 'strength' => 'none'];
        }

        return [
            'direction' => $coefficient > 0 ? 'positive' : 'negative',
            'strength' => $absolute < 0.3 ? 'weak' : ($absolute < 0.6 ? 'moderate' : 'strong'),
        ];
    }

    /** @param list<array<string,mixed>> $points
     * @return array<string,float>
     */
    private function byDate(array $points): array
    {
        $result = [];
        foreach ($points as $point) {
            if (($point['state'] ?? null) === 'ready' && $point['value'] !== null) {
                $result[$point['bucket_start']] = (float) $point['value'];
            }
        }

        return $result;
    }

    /** @param list<float> $left
     * @param  list<float>  $right
     */
    private function pearson(array $left, array $right): ?float
    {
        $count = count($left);
        $meanLeft = array_sum($left) / $count;
        $meanRight = array_sum($right) / $count;
        $covariance = $varianceLeft = $varianceRight = 0.0;
        for ($index = 0; $index < $count; $index++) {
            $leftDelta = $left[$index] - $meanLeft;
            $rightDelta = $right[$index] - $meanRight;
            $covariance += $leftDelta * $rightDelta;
            $varianceLeft += $leftDelta ** 2;
            $varianceRight += $rightDelta ** 2;
        }
        if ($varianceLeft === 0.0 || $varianceRight === 0.0) {
            return null;
        }

        return $covariance / sqrt($varianceLeft * $varianceRight);
    }

    /** @return array<string,mixed> */
    private function unavailable(array $definition, string $from, string $to, int $samples, string $reason): array
    {
        return [
            ...$definition,
            'from' => $from,
            'to' => $to,
            'state' => 'unavailable',
            'coefficient' => null,
            'direction' => null,
            'strength' => null,
            'sample_count' => $samples,
            'reason' => $reason,
        ];
    }
}
