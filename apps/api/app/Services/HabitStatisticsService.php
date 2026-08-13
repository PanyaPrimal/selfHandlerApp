<?php

namespace App\Services;

use App\Models\Habit;
use App\Models\PlannedOccurrence;
use Illuminate\Support\Collection;

class HabitStatisticsService
{
    /**
     * @return array{from:string,to:string,opportunities:int,successes:int,completion_percentage:float,current_streak:int,best_streak:int,numeric_total:float|null}
     */
    public function calculate(Habit $habit, string $from, string $to, string $today): array
    {
        $occurrences = PlannedOccurrence::query()
            ->where('recurring_rule_id', $habit->recurringRule()->value('id'))
            ->where(function ($query) use ($from, $to): void {
                $query->where(function ($original) use ($from, $to): void {
                    $original->whereNull('rescheduled_to')
                        ->whereBetween('occurrence_date', [$from, $to]);
                })->orWhereBetween('rescheduled_to', [$from, $to]);
            })
            ->with('habitLog')
            ->get()
            ->sortBy(fn (PlannedOccurrence $occurrence): string => $this->effectiveDate($occurrence).'|'.sprintf('%010d', $occurrence->id))
            ->values();

        return $this->fromOccurrences($habit, $occurrences, $from, $to, $today);
    }

    /**
     * @param  Collection<int, Habit>  $habits
     * @return array<int, array<string, int|float|string|null>> keyed by habit id
     */
    public function calculateMany(Collection $habits, string $from, string $to, string $today): array
    {
        $habits->loadMissing('recurringRule');
        $byRule = $habits
            ->filter(fn (Habit $habit): bool => $habit->recurringRule !== null)
            ->keyBy(fn (Habit $habit): int => $habit->recurringRule->id);

        $occurrences = $byRule->isEmpty()
            ? collect()
            : PlannedOccurrence::query()
                ->whereIn('recurring_rule_id', $byRule->keys())
                ->where(function ($query) use ($from, $to): void {
                    $query->where(function ($original) use ($from, $to): void {
                        $original->whereNull('rescheduled_to')
                            ->whereBetween('occurrence_date', [$from, $to]);
                    })->orWhereBetween('rescheduled_to', [$from, $to]);
                })
                ->with('habitLog')
                ->get()
                ->groupBy('recurring_rule_id');

        return $habits->mapWithKeys(function (Habit $habit) use ($occurrences, $from, $to, $today): array {
            $rows = $habit->recurringRule
                ? $occurrences->get($habit->recurringRule->id, collect())
                : collect();

            return [$habit->id => $this->fromOccurrences($habit, $rows, $from, $to, $today)];
        })->all();
    }

    /**
     * @param  Collection<int, PlannedOccurrence>  $occurrences
     * @return array{from:string,to:string,opportunities:int,successes:int,completion_percentage:float,current_streak:int,best_streak:int,numeric_total:float|null}
     */
    private function fromOccurrences(Habit $habit, Collection $occurrences, string $from, string $to, string $today): array
    {
        $occurrences = $occurrences
            ->sortBy(fn (PlannedOccurrence $occurrence): string => $this->effectiveDate($occurrence).'|'.sprintf('%010d', $occurrence->id))
            ->values();
        $opportunities = 0;
        $successes = 0;
        $current = 0;
        $best = 0;
        $numericTotal = 0.0;

        foreach ($occurrences as $occurrence) {
            $log = $occurrence->habitLog;
            $resolved = $log !== null || $this->effectiveDate($occurrence) < $today;

            if (! $resolved) {
                continue;
            }

            $opportunities++;
            $successful = $log !== null && $habit->logIsSuccessful($log);

            if ($successful) {
                $successes++;
                $current++;
                $best = max($best, $current);
            } else {
                $current = 0;
            }

            if ($habit->mode === Habit::MODE_NUMERIC && $log?->outcome === 'recorded') {
                $numericTotal += (float) $log->value;
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            'opportunities' => $opportunities,
            'successes' => $successes,
            'completion_percentage' => $opportunities === 0
                ? 0.0
                : round($successes / $opportunities * 100, 3),
            'current_streak' => $current,
            'best_streak' => $best,
            'numeric_total' => $habit->mode === Habit::MODE_NUMERIC ? round($numericTotal, 3) : null,
        ];
    }

    private function effectiveDate(PlannedOccurrence $occurrence): string
    {
        return ($occurrence->rescheduled_to ?? $occurrence->occurrence_date)->format('Y-m-d');
    }
}
