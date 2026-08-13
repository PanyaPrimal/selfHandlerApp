<?php

namespace App\Services;

use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\SupplementCourse;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SupplementAdherenceService
{
    public function __construct(private readonly RecurringRuleExpander $expander) {}

    /** @return array<string, int|float|null> */
    public function forDay(User $user, string $date, ?CarbonImmutable $now = null): array
    {
        return $this->forRange($user, $date, $date, $now)['summary'];
    }

    /** @return array<string, mixed> */
    public function forRange(User $user, string $from, string $to, ?CarbonImmutable $now = null): array
    {
        $timezone = $user->calendarTimezone();
        $first = CarbonImmutable::createFromFormat('!Y-m-d', $from, $timezone);
        $last = CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone);
        if (! $first || ! $last || $first->format('Y-m-d') !== $from || $last->format('Y-m-d') !== $to
            || $from > $to || $first->diffInDays($last) > 365) {
            throw ValidationException::withMessages([
                'to' => __('messages.supplement_range_invalid'),
            ]);
        }
        $now ??= CarbonImmutable::now($timezone);
        $days = [];
        for ($cursor = $first; $cursor->toDateString() <= $to; $cursor = $cursor->addDay()) {
            $days[$cursor->toDateString()] = $this->emptySummary();
        }

        $courses = SupplementCourse::query()->ownedBy($user)
            ->where('starts_on', '<=', $to)->where('ends_on', '>=', $from)
            ->with(['recurringRule.ruleWeekdays', 'recurringRule.ruleSlots'])
            ->orderBy('id')->get();
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
                    $query->whereBetween('occurrence_date', [$from, $to])
                        ->orWhereBetween('rescheduled_to', [$from, $to]);
                })->get();
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

        $nowKey = $now->setTimezone($timezone)->format('Y-m-d H:i');
        foreach ($events as $event) {
            $date = $event['date'];
            if ($event['status'] === PlannedOccurrence::STATUS_DONE) {
                $days[$date]['done']++;
                $days[$date]['eligible']++;
            } elseif ($event['status'] === PlannedOccurrence::STATUS_SKIPPED) {
                $days[$date]['skipped']++;
                $days[$date]['eligible']++;
            } elseif ($date.' '.$event['time'] <= $nowKey) {
                $days[$date]['overdue']++;
                $days[$date]['eligible']++;
            } else {
                $days[$date]['pending']++;
            }
        }

        foreach ($days as &$summary) {
            $summary['adherence_percentage'] = $summary['eligible'] === 0
                ? null
                : round(($summary['done'] / $summary['eligible']) * 100, 2);
        }
        unset($summary);
        $total = $this->emptySummary();
        foreach ($days as $summary) {
            foreach (['done', 'skipped', 'overdue', 'pending', 'eligible'] as $field) {
                $total[$field] += $summary[$field];
            }
        }
        $total['adherence_percentage'] = $total['eligible'] === 0
            ? null
            : round(($total['done'] / $total['eligible']) * 100, 2);

        return [
            'from' => $from,
            'to' => $to,
            'today' => CarbonImmutable::now($timezone)->toDateString(),
            'summary' => $total,
            'days' => collect($days)->map(fn (array $summary, string $date): array => [
                'date' => $date, ...$summary,
            ])->values()->all(),
        ];
    }

    /** @return Collection<int, PlannedOccurrence> */
    public function occurrencesForDay(User $user, string $date): Collection
    {
        $occurrences = PlannedOccurrence::query()->ownedBy($user)
            ->where(function ($query) use ($date): void {
                $query->where(function ($original) use ($date): void {
                    $original->where('occurrence_date', $date)->whereNull('rescheduled_to');
                })->orWhere('rescheduled_to', $date);
            })
            ->whereIn('recurring_rule_id', RecurringRule::query()->ownedBy($user)
                ->where('owner_type', RecurringRule::OWNER_SUPPLEMENT_COURSE)->select('id'))
            ->with(['recurringRule', 'supplementIntake'])
            ->orderBy('occurrence_time')->orderBy('slot')->orderBy('id')->get();
        $courses = SupplementCourse::query()->ownedBy($user)
            ->whereIn('id', $occurrences->pluck('recurringRule.owner_id')->filter())
            ->with(['supplement', 'recurringRule.ruleSlots.supplementDetail'])->get()->keyBy('id');

        return $occurrences->map(function (PlannedOccurrence $occurrence) use ($courses, $user): ?PlannedOccurrence {
            $course = $courses->get($occurrence->recurringRule?->owner_id);
            if (! $course || ($occurrence->status === PlannedOccurrence::STATUS_PLANNED
                && (! $course->is_active || $course->is_archived))) {
                return null;
            }
            $course->supplement->setRelation('user', $user);
            $slot = $course->recurringRule?->ruleSlots->firstWhere('slot', $occurrence->slot);
            $occurrence->setAttribute('course_projection', $course);
            $occurrence->setAttribute(
                'intake_context_projection',
                $slot?->supplementDetail?->intake_context ?? 'unspecified',
            );

            return $occurrence;
        })->filter()->values();
    }

    /** @return array{done:int,skipped:int,overdue:int,pending:int,eligible:int,adherence_percentage:float|null} */
    private function emptySummary(): array
    {
        return [
            'done' => 0, 'skipped' => 0, 'overdue' => 0, 'pending' => 0,
            'eligible' => 0, 'adherence_percentage' => null,
        ];
    }

    private function key(int $ruleId, string $date, string $slot): string
    {
        return $ruleId.'|'.$date.'|'.$slot;
    }
}
