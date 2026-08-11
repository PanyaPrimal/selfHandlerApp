<?php

namespace App\Services;

use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\User;
use Carbon\CarbonImmutable;
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

        $wanted = $enabled ? $this->expander->datesBetween($rule, $from, $to) : [];

        return DB::transaction(function () use ($rule, $from, $to, $wanted): int {
            // One read of the current window, one upsert, one delete: the query
            // count does not grow with the number of days.
            $existing = PlannedOccurrence::query()
                ->where('recurring_rule_id', $rule->id)
                ->whereBetween('occurrence_date', [$from, $to])
                ->get(['id', 'occurrence_date', 'routine_log_id']);

            $known = $existing->pluck('occurrence_date')
                ->map(fn ($date): string => $date->format('Y-m-d'))
                ->all();

            $missing = array_values(array_diff($wanted, $known));

            if ($missing !== []) {
                $now = now();

                PlannedOccurrence::query()->upsert(
                    array_map(fn (string $date): array => [
                        'user_id' => $rule->user_id,
                        'recurring_rule_id' => $rule->id,
                        'occurrence_date' => $date,
                        'slot' => '',
                        'occurrence_time' => $rule->slot_time,
                        'status' => PlannedOccurrence::STATUS_PLANNED,
                        'routine_log_id' => null,
                        'materialized_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $missing),
                    ['recurring_rule_id', 'occurrence_date', 'slot'],
                    ['occurrence_time', 'materialized_at', 'updated_at'],
                );
            }

            // A day the rule no longer produces is removed, unless it is already
            // linked to a fact: that link is evidence, not a prediction.
            $stale = $existing
                ->filter(fn (PlannedOccurrence $occurrence): bool => $occurrence->routine_log_id === null
                    && ! in_array($occurrence->occurrence_date->format('Y-m-d'), $wanted, true))
                ->modelKeys();

            if ($stale !== []) {
                PlannedOccurrence::query()->whereKey($stale)->delete();
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
            ->with('ruleWeekdays')
            ->orderBy('id')
            ->chunk(100, function ($rules) use (&$written, $from): void {
                $enabled = $this->enabledOwners($rules->pluck('owner_id')->all());

                foreach ($rules as $rule) {
                    $written += $this->materialize(
                        $rule,
                        $from,
                        $enabled[$rule->owner_id] ?? false,
                    );
                }
            });

        return $written;
    }

    /**
     * Which routine owners currently want their schedule live.
     *
     * Resolved in one query so `materializeForUser` stays bounded.
     *
     * @param  list<int>  $ownerIds
     * @return array<int, bool>
     */
    private function enabledOwners(array $ownerIds): array
    {
        if ($ownerIds === []) {
            return [];
        }

        return DB::table('routines')
            ->whereIn('id', $ownerIds)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('is_archived', false)
            ->pluck('id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
    }
}
