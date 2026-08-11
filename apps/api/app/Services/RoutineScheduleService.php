<?php

namespace App\Services;

use App\Models\Routine;
use App\ValueObjects\WeekdayCode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Evaluates the deliberately small daily/weekday schedule used by feature 001.
 */
class RoutineScheduleService
{
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

        if ($routine->starts_on && $routine->starts_on->format('Y-m-d') > $dateValue) {
            return false;
        }

        if ($routine->ends_on && $routine->ends_on->format('Y-m-d') < $dateValue) {
            return false;
        }

        return match ($routine->schedule_type) {
            'daily' => true,
            'weekdays' => in_array(
                WeekdayCode::fromDate($calendarDate)->value,
                $routine->weekdays,
                true,
            ),
            default => false,
        };
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
