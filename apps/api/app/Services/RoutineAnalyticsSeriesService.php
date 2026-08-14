<?php

namespace App\Services;

use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\User;

class RoutineAnalyticsSeriesService
{
    /** @return list<array<string,mixed>> */
    public function daily(User $user, string $from, string $to): array
    {
        $occurrences = PlannedOccurrence::query()->ownedBy($user)
            ->whereIn('recurring_rule_id', RecurringRule::query()->ownedBy($user)
                ->where('owner_type', RecurringRule::OWNER_ROUTINE)->select('id'))
            ->where(function ($query) use ($from, $to): void {
                $query->where(function ($original) use ($from, $to): void {
                    $original->whereBetween('occurrence_date', [$from, $to])->whereNull('rescheduled_to');
                })->orWhereBetween('rescheduled_to', [$from, $to]);
            })->get(['occurrence_date', 'rescheduled_to', 'status']);

        $days = [];
        foreach ($occurrences as $occurrence) {
            $date = ($occurrence->rescheduled_to ?? $occurrence->occurrence_date)->format('Y-m-d');
            $days[$date] ??= ['done' => 0, 'scheduled' => 0];
            $days[$date]['scheduled']++;
            if ($occurrence->status === PlannedOccurrence::STATUS_DONE) {
                $days[$date]['done']++;
            }
        }
        ksort($days);

        return array_map(fn (string $date, array $day): array => $this->primitive(
            $date, (string) $day['done'], (string) $day['scheduled'], $day['scheduled'],
        ), array_keys($days), array_values($days));
    }

    /** @return array<string,mixed> */
    private function primitive(string $date, string $numerator, string $denominator, int $samples): array
    {
        return compact('date', 'numerator', 'denominator') + [
            'sample_count' => $samples, 'complete' => true, 'reasons' => [],
        ];
    }
}
