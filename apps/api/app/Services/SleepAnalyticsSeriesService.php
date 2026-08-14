<?php

namespace App\Services;

use App\Models\SleepLog;
use App\Models\User;

class SleepAnalyticsSeriesService
{
    /** @return array{duration:list<array<string,mixed>>,quality:list<array<string,mixed>>} */
    public function daily(User $user, string $from, string $to): array
    {
        $logs = SleepLog::query()->ownedBy($user)->whereBetween('sleep_date', [$from, $to])
            ->orderBy('sleep_date')->orderBy('id')
            ->get(['sleep_date', 'actual_bed_at', 'actual_wake_at', 'quality']);
        $days = [];
        foreach ($logs as $log) {
            $date = $log->sleep_date->format('Y-m-d');
            $days[$date] ??= ['duration' => 0, 'quality' => 0, 'count' => 0];
            $days[$date]['duration'] += $log->durationMinutes();
            $days[$date]['quality'] += $log->quality;
            $days[$date]['count']++;
        }

        $duration = $quality = [];
        foreach ($days as $date => $day) {
            $duration[] = $this->primitive($date, (string) $day['duration'], (string) $day['count'], $day['count']);
            $quality[] = $this->primitive($date, (string) $day['quality'], (string) $day['count'], $day['count']);
        }

        return compact('duration', 'quality');
    }

    /** @return array<string,mixed> */
    private function primitive(string $date, string $numerator, string $denominator, int $samples): array
    {
        return compact('date', 'numerator', 'denominator') + [
            'sample_count' => $samples, 'complete' => true, 'reasons' => [],
        ];
    }
}
