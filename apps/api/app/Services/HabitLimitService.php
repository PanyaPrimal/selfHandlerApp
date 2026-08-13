<?php

namespace App\Services;

use App\Models\Habit;
use App\Models\HabitLimitStep;
use App\Models\HabitLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HabitLimitService
{
    /**
     * @param  list<array{effective_on:string,limit_value:int|float|string,period:string}>  $steps
     * @return Collection<int, HabitLimitStep>
     */
    public function replace(Habit $habit, User $user, array $steps): Collection
    {
        abort_unless($habit->isOwnedBy($user), 404);

        if ($habit->mode !== Habit::MODE_STEPPED_LIMIT) {
            throw ValidationException::withMessages([
                'steps' => __('messages.habit_steps_mode'),
            ]);
        }

        $this->validateSteps($steps);

        DB::transaction(function () use ($habit, $user, $steps): void {
            $habit->limitSteps()->delete();
            foreach ($steps as $step) {
                HabitLimitStep::create([
                    'user_id' => $user->id,
                    'habit_id' => $habit->id,
                    'effective_on' => $step['effective_on'],
                    'limit_value' => round((float) $step['limit_value'], 3),
                    'period' => $step['period'],
                ]);
            }
        });

        $today = CarbonImmutable::now($user->calendarTimezone())->toDateString();

        return $this->decorateSteps($habit->limitSteps()->get(), $today);
    }

    /** @return array<string, mixed>|null */
    public function status(Habit $habit, string $date): ?array
    {
        if ($habit->mode !== Habit::MODE_STEPPED_LIMIT) {
            return null;
        }

        return $this->statusFromSteps($habit, $habit->limitSteps()->get(), $date);
    }

    /**
     * @param  Collection<int, HabitLimitStep>  $rawSteps
     * @return array<string, mixed>
     */
    private function statusFromSteps(
        Habit $habit,
        Collection $rawSteps,
        string $date,
        ?Collection $logs = null,
    ): array {
        $steps = $this->decorateSteps($rawSteps, $date);
        $active = $steps->last(fn (HabitLimitStep $step): bool => $step->effective_on->format('Y-m-d') <= $date);

        if (! $active) {
            return [
                'state' => 'no_active_step',
                'step' => $steps->first() ? $this->stepArray($steps->first()) : null,
                'period_from' => null,
                'period_to' => null,
                'consumed' => 0.0,
                'remaining' => null,
                'within_limit' => null,
            ];
        }

        $selected = CarbonImmutable::parse($date, 'UTC');
        if ($active->period === HabitLimitStep::PERIOD_WEEK) {
            $from = $selected->startOfWeek(CarbonImmutable::MONDAY);
            $to = $from->addDays(6);
        } else {
            $from = $selected;
            $to = $selected;
        }

        $periodLogs = $logs === null
            ? HabitLog::query()
                ->where('habit_id', $habit->id)
                ->where('outcome', HabitLog::OUTCOME_RECORDED)
                ->whereBetween('log_date', [$from->toDateString(), $to->toDateString()])
                ->get(['habit_id', 'log_date', 'value'])
            : $logs->filter(fn (HabitLog $log): bool => $log->habit_id === $habit->id
                && $log->log_date->format('Y-m-d') >= $from->toDateString()
                && $log->log_date->format('Y-m-d') <= $to->toDateString());
        $consumed = (float) $periodLogs->sum('value');
        $limit = (float) $active->limit_value;
        $within = $consumed <= $limit;

        return [
            'state' => $within ? 'within' : 'exceeded',
            'step' => $this->stepArray($active),
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'consumed' => round($consumed, 3),
            'remaining' => round(max(0, $limit - $consumed), 3),
            'within_limit' => $within,
        ];
    }

    /** @return Collection<int, HabitLimitStep> */
    public function stepsForDate(Habit $habit, string $date): Collection
    {
        $habit->loadMissing('limitSteps');

        return $this->decorateSteps($habit->limitSteps, $date);
    }

    /**
     * @param  Collection<int, Habit>  $habits
     * @return array<int, array<string, mixed>|null>
     */
    public function statuses(Collection $habits, string $date): array
    {
        $habits->loadMissing('limitSteps');
        $steppedIds = $habits
            ->where('mode', Habit::MODE_STEPPED_LIMIT)
            ->pluck('id')
            ->all();
        $selected = CarbonImmutable::parse($date, 'UTC');
        $from = $selected->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
        $to = $selected->endOfWeek(CarbonImmutable::SUNDAY)->toDateString();
        $logs = $steppedIds === []
            ? collect()
            : HabitLog::query()
                ->whereIn('habit_id', $steppedIds)
                ->where('outcome', HabitLog::OUTCOME_RECORDED)
                ->whereBetween('log_date', [$from, $to])
                ->get(['habit_id', 'log_date', 'value']);

        return $habits->mapWithKeys(fn (Habit $habit): array => [
            $habit->id => $habit->mode === Habit::MODE_STEPPED_LIMIT
                ? $this->statusFromSteps($habit, $habit->limitSteps, $date, $logs)
                : null,
        ])->all();
    }

    /** @param list<array{effective_on:string,limit_value:int|float|string,period:string}> $steps */
    private function validateSteps(array $steps): void
    {
        if ($steps === [] || count($steps) > 52) {
            throw ValidationException::withMessages(['steps' => __('messages.habit_steps_count')]);
        }

        $previousDate = null;
        $previousRate = null;
        foreach ($steps as $index => $step) {
            $date = $step['effective_on'] ?? null;
            $value = $step['limit_value'] ?? null;
            $period = $step['period'] ?? null;

            if (! is_string($date)
                || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
                || ! is_numeric($value)
                || (float) $value <= 0
                || ! in_array($period, HabitLimitStep::PERIODS, true)) {
                throw ValidationException::withMessages([
                    "steps.{$index}" => __('messages.habit_step_invalid'),
                ]);
            }

            $rate = (float) $value / ($period === HabitLimitStep::PERIOD_WEEK ? 7 : 1);
            if (($previousDate !== null && $date <= $previousDate)
                || ($previousRate !== null && $rate >= $previousRate - 0.0000001)) {
                throw ValidationException::withMessages([
                    "steps.{$index}" => __('messages.habit_steps_order'),
                ]);
            }

            $previousDate = $date;
            $previousRate = $rate;
        }
    }

    /** @param Collection<int, HabitLimitStep> $steps @return Collection<int, HabitLimitStep> */
    private function decorateSteps(Collection $steps, string $date): Collection
    {
        $activeId = $steps
            ->filter(fn (HabitLimitStep $step): bool => $step->effective_on->format('Y-m-d') <= $date)
            ->last()?->id;

        return $steps->each(function (HabitLimitStep $step) use ($activeId, $date): void {
            $step->setAttribute('status', match (true) {
                $step->id === $activeId => 'current',
                $step->effective_on->format('Y-m-d') > $date => 'upcoming',
                default => 'completed',
            });
        });
    }

    /** @return array{id:int,effective_on:string,limit_value:float,period:string,status:string} */
    private function stepArray(HabitLimitStep $step): array
    {
        return [
            'id' => $step->id,
            'effective_on' => $step->effective_on->format('Y-m-d'),
            'limit_value' => (float) $step->limit_value,
            'period' => $step->period,
            'status' => (string) $step->getAttribute('status'),
        ];
    }
}
