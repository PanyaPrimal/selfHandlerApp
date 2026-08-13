<?php

namespace App\Services;

use App\Models\PlannedOccurrence;
use App\Models\Supplement;
use App\Models\SupplementCourse;
use App\Support\NutritionDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SupplementStockForecastService
{
    public const HORIZON_DATES = 730;

    public function __construct(
        private readonly SupplementStockService $stock,
        private readonly RecurringRuleExpander $expander,
    ) {}

    /** @return array<string, mixed> */
    public function forecast(Supplement $supplement, ?array $stock = null, ?string $asOf = null): array
    {
        $stock ??= $this->stock->forSupplement($supplement);
        $timezone = $supplement->user->calendarTimezone();
        $asOf ??= CarbonImmutable::now($timezone)->toDateString();
        $horizon = CarbonImmutable::parse($asOf, $timezone)
            ->addDays(self::HORIZON_DATES - 1)->toDateString();
        $courses = $this->coursesFor($supplement->newCollection([$supplement]), $asOf, $horizon)
            ->where('supplement_id', $supplement->id)->values();
        $durable = $this->durableFor($courses, $supplement->user_id, $asOf, $horizon);

        return $this->project($supplement, $stock, $asOf, $horizon, $courses, $durable);
    }

    /**
     * Forecast a workspace in a fixed number of reads instead of loading each
     * supplement's courses and durable overlay separately.
     *
     * @param  Collection<int, Supplement>  $supplements
     * @param  array<int, array<string, mixed>>  $stocks
     * @return array<int, array<string, mixed>>
     */
    public function forecastMany(Collection $supplements, array $stocks, ?string $asOf = null): array
    {
        if ($supplements->isEmpty()) {
            return [];
        }
        /** @var Supplement $first */
        $first = $supplements->first();
        $timezone = $first->user->calendarTimezone();
        $asOf ??= CarbonImmutable::now($timezone)->toDateString();
        $horizon = CarbonImmutable::parse($asOf, $timezone)
            ->addDays(self::HORIZON_DATES - 1)->toDateString();
        $courses = $this->coursesFor($supplements, $asOf, $horizon);
        $durable = $this->durableFor($courses, $first->user_id, $asOf, $horizon);

        return $supplements->mapWithKeys(fn (Supplement $supplement): array => [
            $supplement->id => $this->project(
                $supplement,
                $stocks[$supplement->id],
                $asOf,
                $horizon,
                $courses->where('supplement_id', $supplement->id)->values(),
                $durable,
            ),
        ])->all();
    }

    /** @param Collection<int, SupplementCourse> $courses @param Collection<int, PlannedOccurrence> $durable */
    private function project(
        Supplement $supplement,
        array $stock,
        string $asOf,
        string $horizon,
        Collection $courses,
        Collection $durable,
    ): array {
        $base = [
            'as_of' => $asOf,
            'horizon_until' => $horizon,
            'remaining_quantity' => $stock['remaining_quantity'],
            'stock_unit' => $supplement->stock_unit,
            'projected_occurrences' => 0,
            'projected_consumption' => '0.000000',
            'runout_on' => null,
            'last_course_end' => null,
        ];

        if (! $stock['has_facts']) {
            return ['status' => 'no_stock', ...$base];
        }
        if (bccomp($stock['remaining_quantity'], '0', 6) <= 0) {
            return ['status' => 'already_depleted', ...$base, 'runout_on' => $asOf];
        }

        if ($courses->isEmpty()) {
            return ['status' => 'no_active_course', ...$base];
        }

        $lastEnd = $courses->max(fn (SupplementCourse $course): string => $course->ends_on->format('Y-m-d'));
        $base['last_course_end'] = $lastEnd;
        $rules = $courses->pluck('recurringRule')->filter();
        $ruleIds = $rules->pluck('id');
        $courseByRule = $courses->keyBy(fn (SupplementCourse $course): ?int => $course->recurringRule?->id);
        $durable = $durable->whereIn('recurring_rule_id', $ruleIds);

        $represented = [];
        $events = [];
        foreach ($durable as $occurrence) {
            $represented[$occurrence->recurring_rule_id.'|'.$occurrence->occurrence_date->format('Y-m-d').'|'.$occurrence->slot] = true;
            $effective = ($occurrence->rescheduled_to ?? $occurrence->occurrence_date)->format('Y-m-d');
            $course = $courseByRule->get($occurrence->recurring_rule_id);
            if ($course && $occurrence->status === PlannedOccurrence::STATUS_PLANNED
                && $effective >= $asOf && $effective <= $horizon) {
                $events[] = $this->event($course, $effective, (string) $occurrence->occurrence_time, (string) $occurrence->slot);
            }
        }

        foreach ($courses as $course) {
            $rule = $course->recurringRule;
            if (! $rule) {
                continue;
            }
            $slots = $rule->ruleSlots->isEmpty()
                ? collect([(object) ['slot' => '', 'occurrence_time' => $rule->slot_time, 'sort_order' => 0]])
                : $rule->ruleSlots;
            foreach ($this->expander->datesBetween($rule, $asOf, $horizon) as $date) {
                foreach ($slots as $slot) {
                    $key = $rule->id.'|'.$date.'|'.$slot->slot;
                    if (! isset($represented[$key])) {
                        $events[] = $this->event($course, $date, (string) $slot->occurrence_time, (string) $slot->slot);
                    }
                }
            }
        }

        usort($events, static fn (array $left, array $right): int => $left['sort'] <=> $right['sort']);
        $projected = '0.000000';
        $remaining = $stock['remaining_quantity'];
        $runout = null;
        foreach ($events as $event) {
            $projected = NutritionDecimal::add($projected, $event['dose'], 6);
            $remaining = NutritionDecimal::add($remaining, '-'.$event['dose'], 6);
            if ($runout === null && bccomp($remaining, '0', 6) <= 0) {
                $runout = $event['date'];
            }
        }
        $base['projected_occurrences'] = count($events);
        $base['projected_consumption'] = $projected;

        if ($runout !== null) {
            return ['status' => 'ready', ...$base, 'runout_on' => $runout];
        }
        if ($events === []) {
            return ['status' => 'no_consumption', ...$base];
        }

        return [
            'status' => $lastEnd > $horizon ? 'beyond_horizon' : 'course_ends_with_stock',
            ...$base,
        ];
    }

    /** @param Collection<int, Supplement> $supplements @return Collection<int, SupplementCourse> */
    private function coursesFor(Collection $supplements, string $asOf, string $horizon): Collection
    {
        return SupplementCourse::query()
            ->where('user_id', $supplements->first()->user_id)
            ->whereIn('supplement_id', $supplements->modelKeys())
            ->where('is_active', true)
            ->where('is_archived', false)
            ->where('ends_on', '>=', $asOf)
            ->where('starts_on', '<=', $horizon)
            ->with(['recurringRule.ruleWeekdays', 'recurringRule.ruleSlots'])
            ->orderBy('id')->get();
    }

    /** @param Collection<int, SupplementCourse> $courses @return Collection<int, PlannedOccurrence> */
    private function durableFor(Collection $courses, int $userId, string $asOf, string $horizon): Collection
    {
        $ruleIds = $courses->pluck('recurringRule.id')->filter();
        if ($ruleIds->isEmpty()) {
            return collect();
        }

        return PlannedOccurrence::query()
            ->where('user_id', $userId)
            ->whereIn('recurring_rule_id', $ruleIds)
            ->where(function ($query) use ($asOf, $horizon): void {
                $query->whereBetween('occurrence_date', [$asOf, $horizon])
                    ->orWhereBetween('rescheduled_to', [$asOf, $horizon]);
            })
            ->get();
    }

    /** @return array{date:string,dose:string,sort:string} */
    private function event(SupplementCourse $course, string $date, string $time, string $slot): array
    {
        return [
            'date' => $date,
            'dose' => (string) $course->dose_quantity,
            'sort' => $date.'|'.$time.'|'.str_pad((string) $course->id, 20, '0', STR_PAD_LEFT).'|'.$slot,
        ];
    }
}
