<?php

namespace App\Services;

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\PlannedOccurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class HabitProjectionService
{
    public function __construct(
        private readonly HabitStatisticsService $statistics,
        private readonly HabitLimitService $limits,
    ) {}

    /**
     * @param  Collection<int, Habit>  $habits
     * @return Collection<int, Habit>
     */
    public function decorate(Collection $habits, User $user, string $date): Collection
    {
        $habits->loadMissing([
            'recurringRule.ruleWeekdays',
            'routine:id,name',
            'goal:id,name',
            'limitSteps',
        ]);

        $today = CarbonImmutable::now($user->calendarTimezone())->toDateString();
        $statistics = $this->statistics->calculateMany($habits, '1900-01-01', $date, $today);
        $limits = $this->limits->statuses($habits, $date);
        $byRule = $habits
            ->filter(fn (Habit $habit): bool => $habit->recurringRule !== null)
            ->keyBy(fn (Habit $habit): int => $habit->recurringRule->id);
        $selected = $byRule->isEmpty()
            ? collect()
            : PlannedOccurrence::query()
                ->whereIn('recurring_rule_id', $byRule->keys())
                ->where(function ($query) use ($date): void {
                    $query->where(function ($original) use ($date): void {
                        $original->where('occurrence_date', $date)->whereNull('rescheduled_to');
                    })->orWhere('rescheduled_to', $date);
                })
                ->with('habitLog')
                ->get()
                ->keyBy('recurring_rule_id');

        return $habits->each(function (Habit $habit) use ($statistics, $limits, $selected, $date, $today): void {
            /** @var PlannedOccurrence|null $occurrence */
            $occurrence = $habit->recurringRule ? $selected->get($habit->recurringRule->id) : null;
            $log = $occurrence?->habitLog;

            $habit->setAttribute('statistics_projection', $statistics[$habit->id]);
            $habit->setAttribute('limit_status_projection', $limits[$habit->id] ?? null);
            $habit->setAttribute(
                'limit_steps_projection',
                $this->limits->stepsForDate($habit, $date)->map(fn ($step): array => [
                    'id' => $step->id,
                    'effective_on' => $step->effective_on->format('Y-m-d'),
                    'limit_value' => (float) $step->limit_value,
                    'period' => $step->period,
                    'status' => (string) $step->getAttribute('status'),
                ])->values()->all(),
            );
            $habit->setAttribute('selected_day_projection', [
                'date' => $date,
                'occurrence_id' => $occurrence?->id,
                'is_scheduled' => $occurrence !== null,
                'is_open' => $occurrence !== null && $log === null && $date <= $today,
                'log' => $log ? $this->log($habit, $log) : null,
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function log(Habit $habit, HabitLog $log): array
    {
        return [
            'id' => $log->id,
            'log_date' => $log->log_date->format('Y-m-d'),
            'outcome' => $log->outcome,
            'value' => $log->value === null ? null : (float) $log->value,
            'occurred_at' => $log->occurred_at?->toISOString(),
            'note' => $log->note,
            'successful' => $habit->logIsSuccessful($log),
        ];
    }
}
