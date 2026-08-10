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
    public function isScheduledFor(Routine $routine, CarbonInterface|string $date): bool
    {
        $calendarDate = $this->calendarDate($date);
        $dateValue = $calendarDate->toDateString();

        if ($routine->trashed() || ! $routine->is_active) {
            return false;
        }

        if ($routine->is_archived && ! $this->wasArchivedAfter($routine, $calendarDate)) {
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

    private function wasArchivedAfter(Routine $routine, CarbonImmutable $date): bool
    {
        if (! $routine->archived_at) {
            return false;
        }

        $archiveDate = CarbonImmutable::instance($routine->archived_at)
            ->setTimezone(config('selfhandler.timezone'))
            ->startOfDay();

        return $date->isBefore($archiveDate);
    }
}
