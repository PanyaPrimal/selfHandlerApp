<?php

namespace App\Services;

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\PlannedOccurrence;
use App\Models\User;

class HabitAnalyticsSeriesService
{
    /** @return list<array<string,mixed>> */
    public function daily(User $user, string $from, string $to): array
    {
        $habits = Habit::query()->ownedBy($user)->with('recurringRule')->get();
        $byRule = $habits->filter(fn (Habit $habit): bool => $habit->recurringRule !== null)
            ->keyBy(fn (Habit $habit): int => $habit->recurringRule->id);
        if ($byRule->isEmpty()) {
            return [];
        }
        $occurrences = PlannedOccurrence::query()->ownedBy($user)->whereIn('recurring_rule_id', $byRule->keys())
            ->where(function ($query) use ($from, $to): void {
                $query->where(function ($original) use ($from, $to): void {
                    $original->whereBetween('occurrence_date', [$from, $to])->whereNull('rescheduled_to');
                })->orWhereBetween('rescheduled_to', [$from, $to]);
            })->with('habitLog')->get();
        $days = [];
        foreach ($occurrences as $occurrence) {
            $habit = $byRule->get($occurrence->recurring_rule_id);
            if (! $habit || ($occurrence->habitLog === null && (! $habit->is_active || $habit->is_archived))) {
                continue;
            }
            $date = ($occurrence->rescheduled_to ?? $occurrence->occurrence_date)->format('Y-m-d');
            $days[$date] ??= ['successful' => 0, 'scheduled' => 0];
            $days[$date]['scheduled']++;
            $log = $occurrence->habitLog;
            if ($log !== null && $log->outcome !== HabitLog::OUTCOME_SKIPPED && $habit->logIsSuccessful($log)) {
                $days[$date]['successful']++;
            }
        }
        ksort($days);

        return array_map(fn (string $date, array $day): array => [
            'date' => $date, 'numerator' => (string) $day['successful'], 'denominator' => (string) $day['scheduled'],
            'sample_count' => $day['scheduled'], 'complete' => true, 'reasons' => [],
        ], array_keys($days), array_values($days));
    }
}
