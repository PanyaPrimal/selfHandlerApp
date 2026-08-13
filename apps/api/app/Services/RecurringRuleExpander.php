<?php

namespace App\Services;

use App\Models\RecurringRule;
use App\ValueObjects\WeekdayCode;
use Carbon\CarbonImmutable;

/**
 * Deterministic expansion of a recurrence rule.
 *
 * Pure by design: no database, no clock, no knowledge of who owns the rule. It
 * answers "does this rule land on this calendar day", which makes it usable for
 * any date — including history that predates materialization, which the routine
 * progress and streak calculations depend on.
 *
 * All arithmetic walks calendar days rather than instants. Stepping an instant
 * across a daylight-saving transition can repeat or skip a local day; stepping
 * `Y-m-d` in the rule's zone cannot.
 */
class RecurringRuleExpander
{
    public function occursOn(RecurringRule $rule, string $date): bool
    {
        if (! $this->withinBounds($rule, $date)) {
            return false;
        }

        if (! $this->withinCycle($rule, $date)) {
            return false;
        }

        return match ($rule->frequency) {
            RecurringRule::FREQUENCY_DAILY => $this->dailyIntervalMatches($rule, $date),
            RecurringRule::FREQUENCY_WEEKLY => $this->weeklyIntervalMatches($rule, $date)
                && in_array($this->weekdayFor($rule, $date), $rule->weekdays, true),
            RecurringRule::FREQUENCY_MONTHLY => $this->monthlyIntervalMatches($rule, $date)
                && in_array((int) substr($date, 8, 2), $rule->monthdays, true),
            default => false,
        };
    }

    /**
     * Inclusive list of calendar days the rule lands on.
     *
     * @return list<string>
     */
    public function datesBetween(RecurringRule $rule, string $from, string $to): array
    {
        if ($from > $to) {
            return [];
        }

        $start = max($from, $rule->starts_on?->format('Y-m-d') ?? $from);
        $end = min($to, $rule->ends_on?->format('Y-m-d') ?? $to);

        if ($start > $end) {
            return [];
        }

        $dates = [];
        $cursor = CarbonImmutable::parse($start, $rule->timezone)->startOfDay();
        $last = CarbonImmutable::parse($end, $rule->timezone)->startOfDay();

        while ($cursor->toDateString() <= $last->toDateString()) {
            $date = $cursor->toDateString();

            if ($this->occursOn($rule, $date)) {
                $dates[] = $date;
            }

            $cursor = $cursor->addDay()->startOfDay();
        }

        return $dates;
    }

    private function withinBounds(RecurringRule $rule, string $date): bool
    {
        if ($rule->starts_on && $rule->starts_on->format('Y-m-d') > $date) {
            return false;
        }

        if ($rule->ends_on) {
            return $rule->ends_on->format('Y-m-d') >= $date;
        }

        if ($rule->frequency === RecurringRule::FREQUENCY_MONTHLY && $rule->starts_on) {
            return $rule->starts_on->copy()->addYears(10)->format('Y-m-d') >= $date;
        }

        return true;
    }

    private function weekdayFor(RecurringRule $rule, string $date): string
    {
        return WeekdayCode::fromDate(
            CarbonImmutable::parse($date, $rule->timezone)->startOfDay(),
        )->value;
    }

    private function dailyIntervalMatches(RecurringRule $rule, string $date): bool
    {
        $interval = max(1, (int) ($rule->interval_count ?? 1));
        if ($interval === 1 || ! $rule->starts_on) {
            return true;
        }

        return $this->daysSinceStart($rule, $date) % $interval === 0;
    }

    private function weeklyIntervalMatches(RecurringRule $rule, string $date): bool
    {
        $interval = max(1, (int) ($rule->interval_count ?? 1));
        if ($interval === 1 || ! $rule->starts_on) {
            return true;
        }

        $anchorWeek = CarbonImmutable::parse($rule->starts_on->format('Y-m-d'), $rule->timezone)
            ->startOfWeek();
        $dateWeek = CarbonImmutable::parse($date, $rule->timezone)->startOfWeek();

        return $anchorWeek->diffInWeeks($dateWeek) % $interval === 0;
    }

    private function monthlyIntervalMatches(RecurringRule $rule, string $date): bool
    {
        if (! $rule->starts_on) {
            return false;
        }
        $interval = max(1, (int) ($rule->interval_count ?? 1));
        $anchor = CarbonImmutable::parse($rule->starts_on->format('Y-m-d'), $rule->timezone)->startOfMonth();
        $month = CarbonImmutable::parse($date, $rule->timezone)->startOfMonth();

        return $anchor->diffInMonths($month) % $interval === 0;
    }

    private function withinCycle(RecurringRule $rule, string $date): bool
    {
        $on = $rule->cycle_on_days;
        $off = $rule->cycle_off_days;
        if ($on === null || $off === null || ! $rule->starts_on) {
            return true;
        }

        $cycle = max(1, (int) $on) + max(1, (int) $off);

        return $this->daysSinceStart($rule, $date) % $cycle < (int) $on;
    }

    private function daysSinceStart(RecurringRule $rule, string $date): int
    {
        return CarbonImmutable::parse($rule->starts_on->format('Y-m-d'), $rule->timezone)
            ->startOfDay()
            ->diffInDays(CarbonImmutable::parse($date, $rule->timezone)->startOfDay());
    }
}
