<?php

namespace App\Services;

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\PlannedOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** A weekly goal uses actual daily facts; unused available days are not misses. */
class HabitWeeklyGoalService
{
    /** @param Collection<int, Habit> $habits @return array<int, array<string, mixed>> */
    public function progress(Collection $habits, string $date): array
    {
        $from = CarbonImmutable::parse($date)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
        $to = CarbonImmutable::parse($from)->addDays(6)->toDateString();
        $flexible = $habits->filter(fn (Habit $habit): bool => $habit->weeklyTargetForDate($date) !== null);
        if ($flexible->isEmpty()) {
            return [];
        }
        $logs = HabitLog::query()->whereIn('habit_id', $flexible->modelKeys())
            ->whereBetween('log_date', [$from, $to])->get()->groupBy('habit_id');

        return $flexible->mapWithKeys(function (Habit $habit) use ($logs, $date, $from, $to): array {
            $target = $habit->weeklyTargetForDate($date);
            $completed = $logs->get($habit->id, collect())
                ->filter(fn (HabitLog $log): bool => $habit->logIsSuccessful($log))->count();

            return [$habit->id => [
                'from' => $from, 'to' => $to, 'target' => $target, 'completed' => $completed,
                'remaining' => max(0, $target - $completed), 'achieved' => $completed >= $target,
            ]];
        })->all();
    }

    /** @param Collection<int, PlannedOccurrence> $occurrences @return array<string, mixed> */
    public function statistics(Habit $habit, Collection $occurrences, string $from, string $to, string $today): array
    {
        $opportunities = $successes = $current = $best = 0;
        $numericTotal = 0.0;
        $weeks = $occurrences->sortBy(fn (PlannedOccurrence $row): string => $this->date($row))
            ->groupBy(fn (PlannedOccurrence $row): string => CarbonImmutable::parse($this->date($row))
                ->startOfWeek(CarbonImmutable::MONDAY)->toDateString());
        foreach ($weeks as $week => $rows) {
            $rows = $rows->unique(fn (PlannedOccurrence $row): string => $this->date($row));
            $target = $habit->weeklyTargetForDate($week);
            foreach ($rows as $row) {
                if ($habit->mode === Habit::MODE_NUMERIC && $row->habitLog?->outcome === HabitLog::OUTCOME_RECORDED) {
                    $numericTotal += (float) $row->habitLog->value;
                }
            }
            if ($target !== null) {
                if ($week > $today) {
                    continue;
                }
                $count = $rows->filter(fn (PlannedOccurrence $row): bool => $row->habitLog !== null
                    && $habit->logIsSuccessful($row->habitLog))->count();
                $opportunities += $target;
                $successes += min($target, $count);
                $end = CarbonImmutable::parse($week)->addDays(6)->toDateString();
                if ($count >= $target) {
                    $current++;
                    $best = max($best, $current);
                } elseif ($end < $today && $end <= $to) {
                    $current = 0;
                }

                continue;
            }
            foreach ($rows as $row) {
                $log = $row->habitLog;
                if ($log === null && $this->date($row) >= $today) {
                    continue;
                }
                $opportunities++;
                if ($log !== null && $habit->logIsSuccessful($log)) {
                    $successes++;
                    $current++;
                    $best = max($best, $current);
                } else {
                    $current = 0;
                }
            }
        }

        return [
            'from' => $from, 'to' => $to, 'opportunities' => $opportunities, 'successes' => $successes,
            'completion_percentage' => $opportunities === 0 ? 0.0 : round($successes / $opportunities * 100, 3),
            'current_streak' => $current, 'best_streak' => $best, 'streak_unit' => 'periods',
            'numeric_total' => $habit->mode === Habit::MODE_NUMERIC ? round($numericTotal, 3) : null,
        ];
    }

    private function date(PlannedOccurrence $row): string
    {
        return ($row->rescheduled_to ?? $row->occurrence_date)->format('Y-m-d');
    }
}
