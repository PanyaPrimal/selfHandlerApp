<?php

namespace App\Services;

use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\SleepOccurrenceDetail;
use App\Models\SleepPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Writes the bounded window of planned occurrences for a rule.
 *
 * Materialization exists so a specific future day has a durable identity that a
 * later feature can reschedule or remind about. It is never the answer to "is
 * this day scheduled" — `RecurringRuleExpander` is, for any date. The window is
 * therefore an index that must always agree with the expansion, which the test
 * suite asserts by set equality.
 *
 * Each rule is written in one transaction with a bounded number of queries, so a
 * long window costs the same as a short one and a failure leaves no partial state.
 */
class RecurrenceMaterializer
{
    public const WINDOW_DAYS = 90;

    public function __construct(private readonly RecurringRuleExpander $expander) {}

    /**
     * Bring one rule's window up to date.
     *
     * @param  bool  $enabled  whether the owner currently wants the schedule live
     * @return int occurrences present in the window after the run
     */
    public function materialize(RecurringRule $rule, ?string $today = null, bool $enabled = true): int
    {
        $from = $today ?? CarbonImmutable::now($rule->timezone)->toDateString();
        $to = CarbonImmutable::parse($from, $rule->timezone)
            ->addDays(self::WINDOW_DAYS)
            ->toDateString();

        $rule->loadMissing('ruleSlots');
        $dates = $enabled ? $this->expander->datesBetween($rule, $from, $to) : [];
        $slots = $rule->ruleSlots->isEmpty()
            ? [['slot' => '', 'occurrence_time' => $rule->slot_time]]
            : $rule->ruleSlots->map(fn ($slot): array => [
                'slot' => $slot->slot,
                'occurrence_time' => $slot->occurrence_time,
            ])->values()->all();
        $wanted = [];
        foreach ($dates as $date) {
            foreach ($slots as $slot) {
                $wanted[] = [
                    'key' => $this->occurrenceKey($date, (string) $slot['slot']),
                    'date' => $date,
                    'slot' => (string) $slot['slot'],
                    'occurrence_time' => $slot['occurrence_time'],
                ];
            }
        }

        return DB::transaction(function () use ($rule, $from, $to, $wanted, $enabled): int {
            // One read of the current window, one upsert, one delete: the query
            // count does not grow with the number of days.
            $existing = PlannedOccurrence::query()
                ->where('recurring_rule_id', $rule->id)
                ->whereBetween('occurrence_date', [$from, $to])
                ->get([
                    'id', 'occurrence_date', 'rescheduled_to', 'slot', 'routine_log_id', 'habit_log_id',
                    'sleep_log_id', 'workout_session_id', 'supplement_intake_id',
                ]);

            $known = $existing->map(fn (PlannedOccurrence $occurrence): string => $this->occurrenceKey(
                $occurrence->occurrence_date->format('Y-m-d'),
                (string) $occurrence->slot,
            ))->all();

            $missing = array_values(array_filter(
                $wanted,
                fn (array $occurrence): bool => ! in_array($occurrence['key'], $known, true),
            ));

            if ($missing !== []) {
                $now = now();

                PlannedOccurrence::query()->upsert(
                    array_map(fn (array $occurrence): array => [
                        'user_id' => $rule->user_id,
                        'recurring_rule_id' => $rule->id,
                        'occurrence_date' => $occurrence['date'],
                        'slot' => $occurrence['slot'],
                        'occurrence_time' => $occurrence['occurrence_time'],
                        'status' => PlannedOccurrence::STATUS_PLANNED,
                        'routine_log_id' => null,
                        'habit_log_id' => null,
                        'sleep_log_id' => null,
                        'workout_session_id' => null,
                        'supplement_intake_id' => null,
                        'materialized_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $missing),
                    ['recurring_rule_id', 'occurrence_date', 'slot'],
                    ['occurrence_time', 'materialized_at', 'updated_at'],
                );
            }

            foreach (collect($wanted)->groupBy('slot') as $slot => $slotOccurrences) {
                PlannedOccurrence::query()
                    ->where('recurring_rule_id', $rule->id)
                    ->where('slot', $slot)
                    ->whereIn('occurrence_date', $slotOccurrences->pluck('date')->all())
                    ->whereNull('routine_log_id')
                    ->whereNull('habit_log_id')
                    ->whereNull('sleep_log_id')
                    ->whereNull('workout_session_id')
                    ->whereNull('supplement_intake_id')
                    ->update([
                        'occurrence_time' => $slotOccurrences->first()['occurrence_time'],
                        'materialized_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            // A day the rule no longer produces is removed, unless the user has
            // already put something of their own on it: a link to a fact is
            // evidence, and a reschedule is a decision they made about a
            // specific day. Neither is a prediction this run may overwrite.
            $stale = $existing
                ->filter(fn (PlannedOccurrence $occurrence): bool => ! $occurrence->hasFact()
                    && $occurrence->rescheduled_to === null
                    && ! in_array($this->occurrenceKey(
                        $occurrence->occurrence_date->format('Y-m-d'),
                        (string) $occurrence->slot,
                    ), array_column($wanted, 'key'), true))
                ->modelKeys();

            if ($stale !== []) {
                PlannedOccurrence::query()->whereKey($stale)->delete();
            }

            if ($rule->owner_type === RecurringRule::OWNER_SLEEP_PLAN) {
                $this->syncSleepDetails($rule, $from, $to, $enabled);
            }

            $rule->forceFill(['last_materialized_until' => $to])->save();

            return PlannedOccurrence::query()
                ->where('recurring_rule_id', $rule->id)
                ->whereBetween('occurrence_date', [$from, $to])
                ->count();
        });
    }

    /**
     * Extend the window for every rule the user owns.
     */
    public function materializeForUser(User $user, ?string $today = null): int
    {
        $timezone = $user->calendarTimezone();
        $from = $today ?? CarbonImmutable::now($timezone)->toDateString();
        $written = 0;

        RecurringRule::query()
            ->ownedBy($user)
            ->with(['ruleWeekdays', 'ruleSlots'])
            ->orderBy('id')
            ->chunk(100, function ($rules) use (&$written, $from): void {
                $enabled = $this->enabledOwners($rules);

                foreach ($rules as $rule) {
                    $written += $this->materialize(
                        $rule,
                        $from,
                        $enabled[$this->ownerKey($rule->owner_type, (int) $rule->owner_id)] ?? false,
                    );
                }
            });

        return $written;
    }

    /**
     * Which polymorphic owners currently want their schedule live.
     *
     * Resolved in one query so `materializeForUser` stays bounded.
     *
     * @return array<int, bool>
     */
    private function enabledOwners(Collection $rules): array
    {
        if ($rules->isEmpty()) {
            return [];
        }

        $enabled = [];

        foreach ($rules->groupBy('owner_type') as $ownerType => $ownerRules) {
            $table = match ($ownerType) {
                RecurringRule::OWNER_ROUTINE => 'routines',
                RecurringRule::OWNER_HABIT => 'habits',
                RecurringRule::OWNER_SLEEP_PLAN => 'sleep_plans',
                RecurringRule::OWNER_WORKOUT_PROGRAM => 'workout_programs',
                RecurringRule::OWNER_SUPPLEMENT_COURSE => 'supplement_courses',
                default => null,
            };

            if ($table === null) {
                continue;
            }

            $query = DB::table($table)
                ->whereIn('id', $ownerRules->pluck('owner_id')->all())
                ->where('is_active', true)
                ->where('is_archived', false);

            if ($ownerType === RecurringRule::OWNER_ROUTINE) {
                $query->whereNull('deleted_at');
            }

            foreach ($query->pluck('id') as $ownerId) {
                $enabled[$this->ownerKey((string) $ownerType, (int) $ownerId)] = true;
            }
        }

        return $enabled;
    }

    private function ownerKey(string $ownerType, int $ownerId): string
    {
        return $ownerType.':'.$ownerId;
    }

    private function occurrenceKey(string $date, string $slot): string
    {
        return $date."\0".$slot;
    }

    private function syncSleepDetails(
        RecurringRule $rule,
        string $from,
        string $to,
        bool $updateUnlinked,
    ): void {
        $plan = SleepPlan::query()
            ->whereKey($rule->owner_id)
            ->where('user_id', $rule->user_id)
            ->first();

        if (! $plan) {
            return;
        }

        $occurrences = PlannedOccurrence::query()
            ->where('recurring_rule_id', $rule->id)
            ->whereBetween('occurrence_date', [$from, $to])
            ->get(['id', 'user_id', 'sleep_log_id']);

        if ($occurrences->isEmpty()) {
            return;
        }

        $now = now();
        SleepOccurrenceDetail::query()->insertOrIgnore($occurrences->map(fn (PlannedOccurrence $occurrence): array => [
            'user_id' => $occurrence->user_id,
            'planned_occurrence_id' => $occurrence->id,
            'planned_wake_time' => $plan->planned_wake_time,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        if ($updateUnlinked) {
            SleepOccurrenceDetail::query()
                ->whereIn('planned_occurrence_id', $occurrences
                    ->whereNull('sleep_log_id')
                    ->pluck('id'))
                ->update([
                    'planned_wake_time' => $plan->planned_wake_time,
                    'updated_at' => $now,
                ]);
        }
    }
}
