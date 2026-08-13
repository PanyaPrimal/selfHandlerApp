<?php

namespace App\Services;

use App\Models\FinanceDebt;
use App\Models\FinanceDebtOccurrenceDetail;
use App\Models\FinanceFundOccurrenceDetail;
use App\Models\FinanceOccurrenceDetail;
use App\Models\FinanceRecurringOperation;
use App\Models\FinanceSavingFund;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\SleepOccurrenceDetail;
use App\Models\SleepPlan;
use App\Models\User;
use App\Services\Finance\FinanceFundProjectionService;
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

    public function __construct(
        private readonly RecurringRuleExpander $expander,
        private readonly FinanceFundProjectionService $fundProjections,
    ) {}

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

        $rule->loadMissing(['ruleSlots', 'ruleMonthdays']);
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
                    'finance_occurrence_fact_id', 'finance_debt_payment_fact_id',
                    'finance_fund_occurrence_fact_id',
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
                        'finance_occurrence_fact_id' => null,
                        'finance_debt_payment_fact_id' => null,
                        'finance_fund_occurrence_fact_id' => null,
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
                    ->whereNull('finance_occurrence_fact_id')
                    ->whereNull('finance_debt_payment_fact_id')
                    ->whereNull('finance_fund_occurrence_fact_id')
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

            if ($rule->owner_type === RecurringRule::OWNER_FINANCE_RECURRING_OPERATION) {
                $this->syncFinanceDetails($rule, $from, $to, $enabled);
            }

            if ($rule->owner_type === RecurringRule::OWNER_FINANCE_DEBT) {
                $this->syncDebtDetails($rule, $from, $to, $enabled);
            }

            if ($rule->owner_type === RecurringRule::OWNER_FINANCE_SAVING_FUND) {
                $this->syncFundDetails($rule, $from, $to, $enabled);
            }

            // A disabled owner keeps no coverage marker: if it is enabled again
            // outside its module service, the next global pass must rebuild it.
            $rule->forceFill(['last_materialized_until' => $enabled ? $to : null])->save();

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
        $until = CarbonImmutable::parse($from, $timezone)->addDays(self::WINDOW_DAYS)->toDateString();
        $written = 0;

        RecurringRule::query()
            ->ownedBy($user)
            ->with(['ruleWeekdays', 'ruleSlots', 'ruleMonthdays'])
            ->orderBy('id')
            ->chunk(100, function ($rules) use (&$written, $from, $until): void {
                $enabled = $this->enabledOwners($rules);

                foreach ($rules as $rule) {
                    $ownerEnabled = $enabled[$this->ownerKey($rule->owner_type, (int) $rule->owner_id)] ?? false;
                    $covered = $rule->last_materialized_until !== null
                        && $rule->last_materialized_until->format('Y-m-d') >= $until;
                    if ($ownerEnabled && $covered) {
                        continue;
                    }
                    $written += $this->materialize(
                        $rule,
                        $from,
                        $ownerEnabled,
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
                RecurringRule::OWNER_FINANCE_RECURRING_OPERATION => 'finance_recurring_operations',
                RecurringRule::OWNER_FINANCE_DEBT => 'finance_debts',
                RecurringRule::OWNER_FINANCE_SAVING_FUND => 'finance_saving_funds',
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

    private function syncFinanceDetails(
        RecurringRule $rule,
        string $from,
        string $to,
        bool $updateUnlinked,
    ): void {
        $operation = FinanceRecurringOperation::query()
            ->whereKey($rule->owner_id)
            ->where('user_id', $rule->user_id)
            ->first();
        if (! $operation) {
            return;
        }

        $occurrences = PlannedOccurrence::query()
            ->where('recurring_rule_id', $rule->id)
            ->whereBetween('occurrence_date', [$from, $to])
            ->get(['id', 'user_id', 'rescheduled_to', 'finance_occurrence_fact_id']);
        if ($occurrences->isEmpty()) {
            return;
        }
        $snapshot = [
            'finance_recurring_operation_id' => $operation->id,
            'operation_name' => $operation->name,
            'direction' => $operation->direction,
            'account_id' => $operation->account_id,
            'category_id' => $operation->category_id,
            'amount' => $operation->amount,
            'currency_code' => $operation->currency_code,
            'is_mandatory' => $operation->is_mandatory,
        ];
        $now = now();
        FinanceOccurrenceDetail::query()->insertOrIgnore($occurrences->map(fn (PlannedOccurrence $occurrence): array => [
            'user_id' => $occurrence->user_id,
            'planned_occurrence_id' => $occurrence->id,
            ...$snapshot,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        if ($updateUnlinked) {
            FinanceOccurrenceDetail::query()
                ->whereIn('planned_occurrence_id', $occurrences
                    ->whereNull('rescheduled_to')
                    ->whereNull('finance_occurrence_fact_id')
                    ->pluck('id'))
                ->update([...$snapshot, 'updated_at' => $now]);
        }
    }

    private function syncDebtDetails(RecurringRule $rule, string $from, string $to, bool $updateUnlinked): void
    {
        $debt = FinanceDebt::query()->whereKey($rule->owner_id)->where('user_id', $rule->user_id)->first();
        if (! $debt) {
            return;
        }
        $occurrences = PlannedOccurrence::query()->where('recurring_rule_id', $rule->id)
            ->whereBetween('occurrence_date', [$from, $to])->get();
        if ($occurrences->isEmpty()) {
            return;
        }
        $snapshot = [
            'finance_debt_id' => $debt->id, 'debt_name' => $debt->name, 'direction' => $debt->direction,
            'account_id' => $debt->account_id, 'category_id' => $debt->category_id,
            'amount' => $debt->installment_amount, 'currency_code' => $debt->currency_code,
        ];
        $now = now();
        FinanceDebtOccurrenceDetail::query()->insertOrIgnore($occurrences->map(fn ($occurrence): array => [
            'user_id' => $occurrence->user_id, 'planned_occurrence_id' => $occurrence->id,
            ...$snapshot, 'created_at' => $now, 'updated_at' => $now,
        ])->all());
        if ($updateUnlinked) {
            FinanceDebtOccurrenceDetail::query()->whereIn('planned_occurrence_id', $occurrences
                ->whereNull('rescheduled_to')->whereNull('finance_debt_payment_fact_id')->pluck('id'))
                ->update([...$snapshot, 'updated_at' => $now]);
        }
    }

    private function syncFundDetails(RecurringRule $rule, string $from, string $to, bool $updateUnlinked): void
    {
        $fund = FinanceSavingFund::query()->whereKey($rule->owner_id)->where('user_id', $rule->user_id)->first();
        if (! $fund) {
            return;
        }
        $occurrences = PlannedOccurrence::query()->where('recurring_rule_id', $rule->id)
            ->whereBetween('occurrence_date', [$from, $to])->get();
        if ($occurrences->isEmpty()) {
            return;
        }
        $base = [
            'finance_saving_fund_id' => $fund->id, 'fund_name' => $fund->name, 'fund_type' => $fund->fund_type,
            'storage_mode' => $fund->storage_mode, 'account_id' => $fund->account_id,
            'funding_account_id' => $fund->funding_account_id, 'category_id' => $fund->category_id,
            'currency_code' => $fund->currency_code, 'top_up_mode' => $fund->top_up_mode,
        ];
        $user = User::query()->find($rule->user_id);
        $projections = $occurrences->mapWithKeys(function ($occurrence) use ($user, $fund): array {
            $month = $occurrence->occurrence_date->format('Y-m');

            return [$month => $this->fundProjections->project($user, $fund, $month)];
        });
        $now = now();
        FinanceFundOccurrenceDetail::query()->insertOrIgnore($occurrences->map(function ($occurrence) use ($base, $projections, $now): array {
            $projection = $projections[$occurrence->occurrence_date->format('Y-m')];

            return ['user_id' => $occurrence->user_id, 'planned_occurrence_id' => $occurrence->id,
                ...$base, 'amount' => $projection['suggested_top_up'], 'calculation_basis' => $projection['calculation_basis'],
                'complete' => $projection['complete'], 'missing_currencies' => json_encode($projection['missing_currencies']),
                'created_at' => $now, 'updated_at' => $now];
        })->all());
        if ($updateUnlinked) {
            foreach ($occurrences->whereNull('rescheduled_to')->whereNull('finance_fund_occurrence_fact_id') as $occurrence) {
                $projection = $projections[$occurrence->occurrence_date->format('Y-m')];
                FinanceFundOccurrenceDetail::query()->where('planned_occurrence_id', $occurrence->id)->update([
                    ...$base, 'amount' => $projection['suggested_top_up'],
                    'calculation_basis' => $projection['calculation_basis'], 'complete' => $projection['complete'],
                    'missing_currencies' => json_encode($projection['missing_currencies']), 'updated_at' => $now,
                ]);
            }
        }
    }
}
