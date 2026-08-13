<?php

namespace App\Services;

use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\SleepLog;
use App\Models\SleepPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class SleepStatisticsService
{
    /** @return array<string, mixed> */
    public function summarize(User $user, string $from, string $to, ?string $selectedDate = null): array
    {
        $fromDate = CarbonImmutable::parse($from, $user->calendarTimezone())->startOfDay();
        $toDate = CarbonImmutable::parse($to, $user->calendarTimezone())->startOfDay();
        if ($toDate->isBefore($fromDate) || $fromDate->diffInDays($toDate) > 365) {
            throw ValidationException::withMessages(['to' => __('messages.sleep_range_invalid')]);
        }

        $rules = RecurringRule::query()
            ->ownedBy($user)
            ->where('owner_type', RecurringRule::OWNER_SLEEP_PLAN)
            ->pluck('id');

        $planned = $this->effectiveOccurrences($user, $rules->all(), $from, $to)->count();
        $logs = SleepLog::query()
            ->ownedBy($user)
            ->whereBetween('sleep_date', [$from, $to])
            ->get();

        $selected = $selectedDate
            ? $this->effectiveOccurrences($user, $rules->all(), $selectedDate, $selectedDate)
                ->with(['recurringRule', 'sleepDetail', 'sleepLog'])
                ->first()
            : null;
        $plans = SleepPlan::query()->ownedBy($user)->get()->keyBy('id');

        return [
            'period_start' => $from,
            'period_end' => $to,
            'planned_nights' => $planned,
            'recorded_nights' => $logs->count(),
            'average_duration_minutes' => $logs->isEmpty()
                ? null
                : round((float) $logs->avg(fn (SleepLog $log): int => $log->durationMinutes()), 2),
            'average_quality' => $logs->isEmpty() ? null : round((float) $logs->avg('quality'), 2),
            'selected_night' => $selected
                ? $this->nightPayload(
                    $selected,
                    $plans->get($selected->recurringRule?->owner_id),
                    $user->calendarTimezone(),
                )
                : null,
        ];
    }

    private function effectiveOccurrences(User $user, array $ruleIds, string $from, string $to)
    {
        return PlannedOccurrence::query()
            ->ownedBy($user)
            ->whereIn('recurring_rule_id', $ruleIds ?: [-1])
            ->where(function ($query) use ($from, $to): void {
                $query->where(function ($original) use ($from, $to): void {
                    $original->whereBetween('occurrence_date', [$from, $to])
                        ->whereNull('rescheduled_to');
                })->orWhereBetween('rescheduled_to', [$from, $to]);
            });
    }

    public function nightPayload(
        PlannedOccurrence $occurrence,
        ?SleepPlan $plan,
        string $timezone,
    ): array {
        $date = ($occurrence->rescheduled_to ?? $occurrence->occurrence_date)->format('Y-m-d');
        $wakeTime = substr((string) $occurrence->sleepDetail?->planned_wake_time, 0, 5);
        $bedTime = substr((string) $occurrence->occurrence_time, 0, 5);

        return [
            'sleep_plan_id' => $plan?->id,
            'date' => $date,
            'occurrence_id' => $occurrence->id,
            'planned_bed_time' => $bedTime,
            'planned_wake_date' => $wakeTime <= $bedTime
                ? CarbonImmutable::parse($date)->addDay()->toDateString()
                : $date,
            'planned_wake_time' => $wakeTime,
            'state' => $occurrence->sleep_log_id ? 'recorded' : 'planned',
            'rescheduled_from' => $occurrence->rescheduled_to ? $occurrence->occurrence_date->format('Y-m-d') : null,
            'log' => $occurrence->sleepLog ? $this->logPayload($occurrence->sleepLog, $timezone) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function logPayload(SleepLog $log, string $timezone): array
    {
        $bed = $log->actual_bed_at->setTimezone($timezone);
        $wake = $log->actual_wake_at->setTimezone($timezone);

        return [
            'id' => $log->id,
            'sleep_plan_id' => $log->sleep_plan_id,
            'sleep_date' => $log->sleep_date->format('Y-m-d'),
            'actual_bed_at' => $log->actual_bed_at->utc()->toISOString(),
            'actual_wake_at' => $log->actual_wake_at->utc()->toISOString(),
            'actual_bed_date' => $bed->format('Y-m-d'),
            'actual_bed_time' => $bed->format('H:i'),
            'actual_wake_date' => $wake->format('Y-m-d'),
            'actual_wake_time' => $wake->format('H:i'),
            'duration_minutes' => $log->durationMinutes(),
            'quality' => $log->quality,
            'note' => $log->note,
        ];
    }
}
