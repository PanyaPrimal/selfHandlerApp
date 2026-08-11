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

        return match ($rule->frequency) {
            RecurringRule::FREQUENCY_DAILY => true,
            RecurringRule::FREQUENCY_WEEKLY => in_array(
                $this->weekdayFor($rule, $date),
                $rule->weekdays,
                true,
            ),
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

        return ! ($rule->ends_on && $rule->ends_on->format('Y-m-d') < $date);
    }

    private function weekdayFor(RecurringRule $rule, string $date): string
    {
        return WeekdayCode::fromDate(
            CarbonImmutable::parse($date, $rule->timezone)->startOfDay(),
        )->value;
    }
}
