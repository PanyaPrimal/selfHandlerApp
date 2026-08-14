<?php

namespace App\Services\Analytics;

class MetricRollupService
{
    /**
     * @param  array<string,mixed>  $definition
     * @param  list<array<string,mixed>>  $primitives
     * @return array<string,mixed>
     */
    public function point(array $definition, array $primitives, string $from, string $to): array
    {
        $eligible = array_values(array_filter(
            $primitives,
            fn (array $row): bool => $row['date'] >= $from && $row['date'] <= $to,
        ));
        $reasons = [];
        foreach ($eligible as $row) {
            if (! ($row['complete'] ?? true)) {
                $reasons = [...$reasons, ...($row['reasons'] ?? [])];
            }
        }
        $reasons = array_values(array_unique($reasons));
        sort($reasons);
        if ($reasons !== []) {
            $sampleCount = array_sum(array_map(
                fn (array $row): int => (int) ($row['sample_count'] ?? 0),
                $eligible,
            ));

            return $this->shape($from, $to, 'incomplete', null, $sampleCount, null, null, $reasons);
        }

        $available = array_values(array_filter(
            $eligible,
            fn (array $row): bool => ($row['complete'] ?? true) && $row['numerator'] !== null,
        ));
        $sampleCount = array_sum(array_map(fn (array $row): int => (int) ($row['sample_count'] ?? 1), $available));
        $precision = (int) $definition['precision'];
        $operator = $definition['operator'];

        if ($operator === 'sum') {
            if ($available === [] && ! $definition['empty_is_zero']) {
                return $this->empty($from, $to);
            }
            $sum = $this->sum(array_column($available, 'numerator'));

            return $this->shape($from, $to, 'ready', $this->format($sum, $precision), $sampleCount);
        }

        if ($operator === 'last') {
            if ($available === []) {
                return $this->empty($from, $to);
            }
            usort($available, fn (array $left, array $right): int => $left['date'] <=> $right['date']);
            $last = $available[array_key_last($available)];

            return $this->shape(
                $from, $to, 'ready', $this->format($last['numerator'], $precision), $sampleCount,
            );
        }

        $withDenominator = array_values(array_filter(
            $available,
            fn (array $row): bool => $row['denominator'] !== null && bccomp((string) $row['denominator'], '0', 8) > 0,
        ));
        if ($withDenominator === []) {
            return $this->empty($from, $to);
        }
        $numerator = $this->sum(array_column($withDenominator, 'numerator'));
        $denominator = $this->sum(array_column($withDenominator, 'denominator'));
        $raw = bcdiv($numerator, $denominator, 12);
        if ($operator === 'percentage') {
            $raw = bcmul($raw, '100', 12);
        }

        return $this->shape(
            $from,
            $to,
            'ready',
            $this->format($raw, $precision),
            array_sum(array_map(fn (array $row): int => (int) ($row['sample_count'] ?? 1), $withDenominator)),
            $this->format($numerator, $precision),
            $this->format($denominator, $precision),
        );
    }

    /**
     * @param  array<string,mixed>  $definition
     * @param  list<array<string,mixed>>  $primitives
     * @param  list<array{start:string,end:string}>  $buckets
     * @return list<array<string,mixed>>
     */
    public function points(array $definition, array $primitives, array $buckets): array
    {
        return array_map(
            fn (array $bucket): array => $this->point($definition, $primitives, $bucket['start'], $bucket['end']),
            $buckets,
        );
    }

    /** @param list<string|int|float|null> $values */
    private function sum(array $values): string
    {
        $sum = '0';
        foreach ($values as $value) {
            if ($value !== null) {
                $sum = bcadd($sum, (string) $value, 12);
            }
        }

        return $sum;
    }

    private function format(string|int|float $value, int $precision): string
    {
        return bcround((string) $value, $precision);
    }

    /** @return array<string,mixed> */
    private function empty(string $from, string $to): array
    {
        return $this->shape($from, $to, 'empty', null, 0, null, null, ['missing_evidence']);
    }

    /** @return array<string,mixed> */
    private function shape(
        string $from,
        string $to,
        string $state,
        ?string $value,
        int $sampleCount,
        ?string $numerator = null,
        ?string $denominator = null,
        array $reasons = [],
    ): array {
        return [
            'bucket_start' => $from,
            'bucket_end' => $to,
            'state' => $state,
            'value' => $value,
            'sample_count' => $sampleCount,
            'numerator' => $numerator,
            'denominator' => $denominator,
            'reasons' => $reasons,
        ];
    }
}
