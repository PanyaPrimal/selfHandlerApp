<?php

namespace App\Services\Analytics;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AnalyticsWorkspaceService
{
    public function __construct(
        private readonly AnalyticsCatalog $catalog,
        private readonly AnalyticsRegistry $registry,
        private readonly DateBucketFactory $buckets,
        private readonly MetricRollupService $rollups,
        private readonly TrendService $trends,
        private readonly CorrelationService $correlations,
    ) {}

    /** @return array<string,mixed> */
    public function workspace(
        User $user,
        string $metric,
        string $from,
        string $to,
        string $granularity,
        bool $compare,
    ): array {
        return DB::transaction(function () use ($user, $metric, $from, $to, $granularity, $compare): array {
            $timezone = $user->calendarTimezone();
            $definition = $this->catalog->definition($metric);
            $previous = $compare ? $this->buckets->previousRange($from, $to, $timezone) : null;
            $combinedFrom = $previous['from'] ?? $from;
            $primitive = $this->registry->daily($user, $combinedFrom, $to, [$metric])[$metric];
            $points = $this->rollups->points(
                $definition,
                $primitive,
                $this->buckets->make($from, $to, $granularity, $timezone),
            );
            $comparison = null;
            if ($previous !== null) {
                $currentAggregate = $this->aggregate($definition, $primitive, $from, $to);
                $previousAggregate = $this->aggregate($definition, $primitive, $previous['from'], $previous['to']);
                $comparison = [
                    'current' => $currentAggregate,
                    'previous' => $previousAggregate,
                    ...$this->trends->compare($currentAggregate, $previousAggregate, (int) $definition['precision']),
                ];
            }

            return [
                'period' => compact('from', 'to', 'granularity', 'timezone'),
                'metric' => $definition,
                'currency' => $definition['unit'] === 'currency' ? $user->ensureProfile()->base_currency : null,
                'points' => $points,
                'trend' => $this->trends->summarize($points, (int) $definition['precision']),
                'comparison' => $comparison,
            ];
        }, 3);
    }

    /** @return array<string,mixed> */
    public function correlations(User $user, string $from, string $to): array
    {
        return DB::transaction(function () use ($user, $from, $to): array {
            $timezone = $user->calendarTimezone();
            $definitions = $this->catalog->correlations();
            $keys = array_values(array_unique(array_merge(
                array_column($definitions, 'left_metric'),
                array_column($definitions, 'right_metric'),
            )));
            $primitives = $this->registry->daily($user, $from, $to, $keys);
            $buckets = $this->buckets->make($from, $to, 'daily', $timezone);
            $points = [];
            foreach ($keys as $key) {
                $points[$key] = $this->rollups->points(
                    $this->catalog->definition($key), $primitives[$key], $buckets,
                );
            }
            $findings = array_map(fn (array $definition): array => $this->correlations->finding(
                $definition,
                $points[$definition['left_metric']],
                $points[$definition['right_metric']],
                $from,
                $to,
            ), $definitions);

            return ['period' => compact('from', 'to', 'timezone'), 'findings' => $findings];
        }, 3);
    }

    /** @return array<string,mixed> */
    private function aggregate(array $definition, array $primitives, string $from, string $to): array
    {
        $point = $this->rollups->point($definition, $primitives, $from, $to);

        return [
            'from' => $point['bucket_start'], 'to' => $point['bucket_end'], 'state' => $point['state'],
            'value' => $point['value'], 'sample_count' => $point['sample_count'],
            'numerator' => $point['numerator'], 'denominator' => $point['denominator'], 'reasons' => $point['reasons'],
        ];
    }
}
