<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class DateBucketFactory
{
    /** @return list<array{start:string,end:string}> */
    public function make(string $from, string $to, string $granularity, string $timezone): array
    {
        $first = CarbonImmutable::createFromFormat('!Y-m-d', $from, $timezone);
        $last = CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone);
        if (! $first || ! $last || $from > $to || ! in_array($granularity, ['daily', 'weekly', 'monthly'], true)) {
            throw new InvalidArgumentException('Invalid Analytics bucket range.');
        }

        $buckets = [];
        for ($cursor = $first; $cursor->lte($last);) {
            $naturalEnd = match ($granularity) {
                'daily' => $cursor,
                'weekly' => $cursor->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay(),
                'monthly' => $cursor->endOfMonth()->startOfDay(),
            };
            $end = $naturalEnd->min($last);
            $buckets[] = ['start' => $cursor->toDateString(), 'end' => $end->toDateString()];
            $cursor = $end->addDay();
        }

        return $buckets;
    }

    /** @return array{from:string,to:string} */
    public function previousRange(string $from, string $to, string $timezone): array
    {
        $first = CarbonImmutable::createFromFormat('!Y-m-d', $from, $timezone);
        $last = CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone);
        if (! $first || ! $last || $first->gt($last)) {
            throw new InvalidArgumentException('Invalid Analytics comparison range.');
        }

        $days = $first->diffInDays($last) + 1;
        $previousTo = $first->subDay();

        return [
            'from' => $previousTo->subDays($days - 1)->toDateString(),
            'to' => $previousTo->toDateString(),
        ];
    }
}
