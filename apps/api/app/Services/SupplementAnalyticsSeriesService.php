<?php

namespace App\Services;

use App\Models\PlannedOccurrence;
use App\Models\SupplementCourse;
use App\Models\User;
use Carbon\CarbonImmutable;

class SupplementAnalyticsSeriesService
{
    public function __construct(private readonly RecurringRuleExpander $expander) {}

    /** @return list<array<string,mixed>> */
    public function daily(User $user, string $from, string $to, ?CarbonImmutable $now = null): array
    {
        $timezone = $user->calendarTimezone();
        $now ??= CarbonImmutable::now($timezone);
        $courses = SupplementCourse::query()->ownedBy($user)
            ->where('starts_on', '<=', $to)->where('ends_on', '>=', $from)
            ->with(['recurringRule.ruleWeekdays', 'recurringRule.ruleSlots'])->orderBy('id')->get();
        $rules = $courses->pluck('recurringRule')->filter();
        $courseByRule = $courses->keyBy(fn (SupplementCourse $course): ?int => $course->recurringRule?->id);
        $events = [];

        foreach ($courses->where('is_active', true)->where('is_archived', false) as $course) {
            $rule = $course->recurringRule;
            if (! $rule) {
                continue;
            }
            $slots = $rule->ruleSlots->isEmpty()
                ? collect([(object) ['slot' => '', 'occurrence_time' => $rule->slot_time]])
                : $rule->ruleSlots;
            foreach ($this->expander->datesBetween($rule, $from, $to) as $date) {
                foreach ($slots as $slot) {
                    $events[$this->key($rule->id, $date, (string) $slot->slot)] = [
                        'date' => $date,
                        'time' => substr((string) $slot->occurrence_time, 0, 5),
                        'status' => PlannedOccurrence::STATUS_PLANNED,
                    ];
                }
            }
        }

        if ($rules->isNotEmpty()) {
            $durable = PlannedOccurrence::query()->ownedBy($user)
                ->whereIn('recurring_rule_id', $rules->pluck('id'))
                ->where(function ($query) use ($from, $to): void {
                    $query->whereBetween('occurrence_date', [$from, $to])->orWhereBetween('rescheduled_to', [$from, $to]);
                })->get(['id', 'recurring_rule_id', 'occurrence_date', 'rescheduled_to', 'slot', 'occurrence_time', 'status']);
            foreach ($durable as $occurrence) {
                unset($events[$this->key(
                    $occurrence->recurring_rule_id,
                    $occurrence->occurrence_date->format('Y-m-d'),
                    (string) $occurrence->slot,
                )]);
                $effective = ($occurrence->rescheduled_to ?? $occurrence->occurrence_date)->format('Y-m-d');
                $course = $courseByRule->get($occurrence->recurring_rule_id);
                if ($effective < $from || $effective > $to || ! $course
                    || ($occurrence->status === PlannedOccurrence::STATUS_PLANNED
                        && (! $course->is_active || $course->is_archived))) {
                    continue;
                }
                $events['durable|'.$occurrence->id] = [
                    'date' => $effective,
                    'time' => substr((string) $occurrence->occurrence_time, 0, 5),
                    'status' => $occurrence->status,
                ];
            }
        }

        $days = [];
        $nowKey = $now->setTimezone($timezone)->format('Y-m-d H:i');
        foreach ($events as $event) {
            if (! in_array($event['status'], [PlannedOccurrence::STATUS_DONE, PlannedOccurrence::STATUS_SKIPPED], true)
                && $nowKey < $event['date'].' '.$event['time']) {
                continue;
            }
            $days[$event['date']] ??= ['done' => 0, 'eligible' => 0];
            $days[$event['date']]['eligible']++;
            if ($event['status'] === PlannedOccurrence::STATUS_DONE) {
                $days[$event['date']]['done']++;
            }
        }
        ksort($days);

        return array_map(fn (string $date, array $day): array => [
            'date' => $date, 'numerator' => (string) $day['done'], 'denominator' => (string) $day['eligible'],
            'sample_count' => $day['eligible'], 'complete' => true, 'reasons' => [],
        ], array_keys($days), array_values($days));
    }

    private function key(int $ruleId, string $date, string $slot): string
    {
        return $ruleId.'|'.$date.'|'.$slot;
    }
}
