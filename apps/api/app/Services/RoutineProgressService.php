<?php

namespace App\Services;

use App\Models\Routine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RoutineProgressService
{
    public function __construct(private readonly RoutineScheduleService $scheduleService) {}

    /**
     * Calculate the selected user's seven-day completion and current streaks.
     *
     * Logs are streamed in routine/date order so a dense history does not need
     * to be materialized as thousands of Eloquent models in memory.
     *
     * @return array{
     *     period_start: string,
     *     period_end: string,
     *     seven_day: array{scheduled: int, done: int, skipped: int, pending: int, completion_rate: float},
     *     routine_streaks: array<int, int>
     * }
     */
    public function calculate(User $user, CarbonInterface|string $selectedDate): array
    {
        $date = $this->calendarDate($selectedDate);
        $periodStart = $date->subDays(6);
        $today = CarbonImmutable::now(config('selfhandler.timezone'))->startOfDay();
        /** @var Collection<int, Routine> $routines */
        $routines = Routine::query()
            ->ownedBy($user)
            ->with('scheduleWeekdays')
            ->orderBy('id')
            ->get();

        $scheduled = $this->countScheduledOccurrences($routines, $periodStart, $date);
        $done = 0;
        $skipped = 0;
        $routineStreaks = $routines
            ->mapWithKeys(fn (Routine $routine): array => [$routine->id => 0])
            ->all();

        if ($routines->isNotEmpty()) {
            $routineMap = $routines->keyBy('id');
            $logs = $this->logQuery($user, $routines, $periodStart, $date);
            $currentRoutine = null;
            $streakState = null;

            foreach ($logs->cursor() as $log) {
                $routine = $routineMap->get((int) $log->routine_id);

                if (! $routine) {
                    continue;
                }

                $logDateValue = (string) $log->log_date;
                $logDate = CarbonImmutable::parse($logDateValue, config('selfhandler.timezone'))->startOfDay();
                $isScheduled = $this->scheduleService->isScheduledFor($routine, $logDate);

                if (
                    $isScheduled
                    && $logDateValue >= $periodStart->toDateString()
                    && $logDateValue <= $date->toDateString()
                ) {
                    if ($log->status === 'done') {
                        $done++;
                    } elseif ($log->status === 'skipped') {
                        $skipped++;
                    }
                }

                if ($currentRoutine?->id !== $routine->id) {
                    if ($currentRoutine && $streakState) {
                        $this->finishStreak($currentRoutine, $streakState, $today);
                        $routineStreaks[$currentRoutine->id] = $streakState['count'];
                    }

                    $currentRoutine = $routine;
                    $streakState = $this->newStreakState($date);
                }

                $streakState['oldest_log_date'] = $logDate;

                if ($isScheduled && ! $streakState['broken']) {
                    $this->applyStreakLog($routine, $streakState, $logDate, (string) $log->status, $today);
                }
            }

            if ($currentRoutine && $streakState) {
                $this->finishStreak($currentRoutine, $streakState, $today);
                $routineStreaks[$currentRoutine->id] = $streakState['count'];
            }
        }

        $pending = max(0, $scheduled - $done - $skipped);

        return [
            'period_start' => $periodStart->toDateString(),
            'period_end' => $date->toDateString(),
            'seven_day' => [
                'scheduled' => $scheduled,
                'done' => $done,
                'skipped' => $skipped,
                'pending' => $pending,
                'completion_rate' => $scheduled === 0 ? 0.0 : round(($done / $scheduled) * 100, 2),
            ],
            'routine_streaks' => $routineStreaks,
        ];
    }

    /**
     * @param  Collection<int, Routine>  $routines
     */
    private function countScheduledOccurrences(
        Collection $routines,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): int {
        $scheduled = 0;

        for ($date = $periodStart; $date->lessThanOrEqualTo($periodEnd); $date = $date->addDay()) {
            foreach ($routines as $routine) {
                if ($this->scheduleService->isScheduledFor($routine, $date)) {
                    $scheduled++;
                }
            }
        }

        return $scheduled;
    }

    /**
     * @param  Collection<int, Routine>  $routines
     */
    private function logQuery(
        User $user,
        Collection $routines,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
    ): Builder {
        $historyStart = $periodStart;
        $unboundedRoutineIds = [];

        foreach ($routines as $routine) {
            if ($routine->starts_on) {
                $startsOn = CarbonImmutable::parse(
                    $routine->starts_on->format('Y-m-d'),
                    config('selfhandler.timezone'),
                )->startOfDay();

                if ($startsOn->isBefore($historyStart)) {
                    $historyStart = $startsOn;
                }
            } else {
                $unboundedRoutineIds[] = $routine->id;
            }
        }

        if ($unboundedRoutineIds !== []) {
            $oldestLog = DB::table('routine_logs')
                ->where('user_id', $user->id)
                ->whereIn('routine_id', $unboundedRoutineIds)
                ->where('log_date', '<=', $periodEnd->toDateString())
                ->min('log_date');

            if (is_string($oldestLog) && $oldestLog < $historyStart->toDateString()) {
                $historyStart = CarbonImmutable::parse($oldestLog, config('selfhandler.timezone'))->startOfDay();
            }
        }

        return DB::table('routine_logs')
            ->select(['routine_id', 'log_date', 'status'])
            ->where('user_id', $user->id)
            ->whereIn('routine_id', $routines->modelKeys())
            ->whereBetween('log_date', [$historyStart->toDateString(), $periodEnd->toDateString()])
            ->orderBy('routine_id')
            ->orderByDesc('log_date');
    }

    /**
     * @return array{
     *     count: int,
     *     broken: bool,
     *     next_date: CarbonImmutable,
     *     oldest_log_date: CarbonImmutable|null
     * }
     */
    private function newStreakState(CarbonImmutable $selectedDate): array
    {
        return [
            'count' => 0,
            'broken' => false,
            'next_date' => $selectedDate,
            'oldest_log_date' => null,
        ];
    }

    /**
     * @param  array{
     *     count: int,
     *     broken: bool,
     *     next_date: CarbonImmutable,
     *     oldest_log_date: CarbonImmutable|null
     * }  $state
     */
    private function applyStreakLog(
        Routine $routine,
        array &$state,
        CarbonImmutable $logDate,
        string $status,
        CarbonImmutable $today,
    ): void {
        while ($state['next_date']->greaterThanOrEqualTo($logDate)) {
            $candidate = $state['next_date'];
            $state['next_date'] = $candidate->subDay();

            if (! $this->scheduleService->isScheduledFor($routine, $candidate)) {
                continue;
            }

            if ($candidate->isAfter($logDate)) {
                if ($candidate->isBefore($today)) {
                    $state['broken'] = true;

                    return;
                }

                continue;
            }

            if ($status === 'done') {
                $state['count']++;
            } else {
                $state['broken'] = true;
            }

            return;
        }
    }

    /**
     * @param  array{
     *     count: int,
     *     broken: bool,
     *     next_date: CarbonImmutable,
     *     oldest_log_date: CarbonImmutable|null
     * }  $state
     */
    private function finishStreak(Routine $routine, array &$state, CarbonImmutable $today): void
    {
        if ($state['broken']) {
            return;
        }

        $lowerBound = $routine->starts_on
            ? CarbonImmutable::parse(
                $routine->starts_on->format('Y-m-d'),
                config('selfhandler.timezone'),
            )->startOfDay()
            : ($state['oldest_log_date'] ?? $state['next_date'])->subDays(7);

        while ($state['next_date']->greaterThanOrEqualTo($lowerBound)) {
            $candidate = $state['next_date'];
            $state['next_date'] = $candidate->subDay();

            if (! $this->scheduleService->isScheduledFor($routine, $candidate)) {
                continue;
            }

            if ($candidate->isBefore($today)) {
                $state['broken'] = true;

                return;
            }
        }
    }

    private function calendarDate(CarbonInterface|string $date): CarbonImmutable
    {
        $timezone = config('selfhandler.timezone');

        if (is_string($date)) {
            return CarbonImmutable::parse($date, $timezone)->startOfDay();
        }

        return CarbonImmutable::instance($date)
            ->setTimezone($timezone)
            ->startOfDay();
    }
}
