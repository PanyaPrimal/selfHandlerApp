<?php

namespace App\Services;

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\PlannedOccurrence;
use App\Models\User;

class HabitPeriodSummaryService
{
    /** @return array<string,int|float|null> */
    public function summarize(User $user, string $from, string $to): array
    {
        $habits = Habit::query()->ownedBy($user)->with('recurringRule')->get();
        $byRule = $habits->filter(fn (Habit $habit): bool => $habit->recurringRule !== null)
            ->keyBy(fn (Habit $habit): int => $habit->recurringRule->id);
        $occurrences = $byRule->isEmpty() ? collect() : PlannedOccurrence::query()->ownedBy($user)
            ->whereIn('recurring_rule_id', $byRule->keys())
            ->where(function ($query) use ($from, $to): void {
                $query->where(function ($original) use ($from, $to): void {
                    $original->whereBetween('occurrence_date', [$from, $to])->whereNull('rescheduled_to');
                })->orWhereBetween('rescheduled_to', [$from, $to]);
            })->with('habitLog')->get();

        $scheduled = $successful = $skipped = $pending = $unsuccessful = 0;
        $involved = [];
        foreach ($occurrences as $occurrence) {
            $habit = $byRule->get($occurrence->recurring_rule_id);
            if (! $habit || ($occurrence->habitLog === null && (! $habit->is_active || $habit->is_archived))) {
                continue;
            }
            $scheduled++;
            $involved[$habit->id] = true;
            $log = $occurrence->habitLog;
            if ($log === null) {
                $pending++;
            } elseif ($log->outcome === HabitLog::OUTCOME_SKIPPED) {
                $skipped++;
            } elseif ($habit->logIsSuccessful($log)) {
                $successful++;
            } else {
                $unsuccessful++;
            }
        }

        return [
            'scheduled' => $scheduled, 'done' => $successful, 'successful' => $successful,
            'unsuccessful' => $unsuccessful, 'skipped' => $skipped, 'pending' => $pending,
            'completion_rate' => $scheduled === 0 ? null : round($successful / $scheduled * 100, 2),
            'habit_count' => count($involved),
        ];
    }
}
