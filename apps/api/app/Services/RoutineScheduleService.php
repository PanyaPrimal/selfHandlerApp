<?php

namespace App\Services;

use App\Models\Routine;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Owner-aware view of a routine's schedule.
 *
 * The recurrence rule decides which calendar days the pattern lands on; this
 * facade adds the routine lifecycle the engine deliberately knows nothing about
 * — deleted, paused, archived. The signature is unchanged from feature 001, so
 * Today, progress and streaks keep calling it exactly as before.
 */
class RoutineScheduleService
{
    public function __construct(private readonly RecurringRuleExpander $expander) {}

    public function isScheduledFor(Routine $routine, CarbonInterface|string $date, ?string $timezone = null): bool
    {
        $timezone ??= config('selfhandler.timezone');
        $calendarDate = $this->calendarDate($date, $timezone);
        $dateValue = $calendarDate->toDateString();

        if ($routine->trashed() || ! $routine->is_active) {
            return false;
        }

        if ($routine->is_archived && ! $this->wasArchivedAfter($routine, $calendarDate, $timezone)) {
            return false;
        }

        $rule = $routine->recurringRule;

        if (! $rule) {
            return false;
        }

        return $this->expander->occursOn($rule, $dateValue);
    }

    private function calendarDate(CarbonInterface|string $date, string $timezone): CarbonImmutable
    {
        if (is_string($date)) {
            return CarbonImmutable::parse($date, $timezone)->startOfDay();
        }

        return CarbonImmutable::instance($date)
            ->setTimezone($timezone)
            ->startOfDay();
    }

    private function wasArchivedAfter(Routine $routine, CarbonImmutable $date, string $timezone): bool
    {
        if (! $routine->archived_at) {
            return false;
        }

        $archiveDate = CarbonImmutable::instance($routine->archived_at)
            ->setTimezone($timezone)
            ->startOfDay();

        return $date->isBefore($archiveDate);
    }
}
