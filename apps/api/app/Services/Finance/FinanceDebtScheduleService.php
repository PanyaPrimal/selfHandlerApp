<?php

namespace App\Services\Finance;

use App\Models\FinanceDebt;
use App\Models\FinanceDebtOccurrenceDetail;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceDebtScheduleService
{
    /** @param array<string, mixed> $schedule */
    public function validate(FinanceDebt $debt, array $schedule): array
    {
        $amount = $this->positive((string) ($schedule['installment_amount'] ?? ''), 'schedule.installment_amount');
        $count = (int) ($schedule['installment_count'] ?? 0);
        $interval = (int) ($schedule['interval_months'] ?? 0);
        $monthday = (int) ($schedule['monthday'] ?? 0);
        $first = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($schedule['first_due_on'] ?? ''));

        if ($count < 1 || $count > 120 || $interval < 1 || $interval > 12 || $monthday < 1 || $monthday > 31
            || ! $first || $first->day !== $monthday || $first->lt($debt->originated_on)) {
            throw ValidationException::withMessages(['schedule' => __('messages.finance_debt_schedule_invalid')]);
        }
        if (bccomp(bcmul($amount, (string) $count, 4), (string) $debt->original_amount, 4) !== 0) {
            throw ValidationException::withMessages(['schedule.installment_amount' => __('messages.finance_debt_schedule_total')]);
        }

        $normalized = [
            'installment_amount' => $amount,
            'installment_count' => $count,
            'interval_months' => $interval,
            'monthday' => $monthday,
            'first_due_on' => $first->toDateString(),
            'reminder_time' => $schedule['reminder_time'] ?? null,
        ];
        $dates = $this->dates($normalized);
        if (CarbonImmutable::parse($dates[array_key_last($dates)])->gt($first->addYears(10))) {
            throw ValidationException::withMessages(['schedule.installment_count' => __('messages.finance_debt_schedule_too_long')]);
        }

        return $normalized;
    }

    /** @param array<string, mixed> $schedule @return list<string> */
    public function dates(array $schedule): array
    {
        $month = CarbonImmutable::parse((string) $schedule['first_due_on'])->startOfMonth();
        $dates = [];
        while (count($dates) < (int) $schedule['installment_count']) {
            if ((int) $schedule['monthday'] <= $month->daysInMonth) {
                $dates[] = $month->setDay((int) $schedule['monthday'])->toDateString();
            }
            $month = $month->addMonthsNoOverflow((int) $schedule['interval_months'])->startOfMonth();
        }

        return $dates;
    }

    /** @param array<string, mixed> $schedule */
    public function createRuleAndOccurrences(FinanceDebt $debt, array $schedule, string $timezone): RecurringRule
    {
        return DB::transaction(function () use ($debt, $schedule, $timezone): RecurringRule {
            $dates = $this->dates($schedule);
            $rule = RecurringRule::query()->create([
                'user_id' => $debt->user_id,
                'owner_type' => RecurringRule::OWNER_FINANCE_DEBT,
                'owner_id' => $debt->id,
                'frequency' => RecurringRule::FREQUENCY_MONTHLY,
                'interval_count' => $schedule['interval_months'],
                'starts_on' => $schedule['first_due_on'],
                'ends_on' => $dates[array_key_last($dates)],
                'last_materialized_until' => $dates[array_key_last($dates)],
                'timezone' => $timezone,
                'slot_time' => $schedule['reminder_time'],
            ]);
            $rule->syncMonthdays([$schedule['monthday']]);
            $this->materialize($debt, $rule, $dates);

            return $rule->fresh(['ruleMonthdays']);
        });
    }

    /** @param array<string, mixed> $schedule */
    public function synchronize(FinanceDebt $debt, array $schedule, string $timezone): RecurringRule
    {
        return DB::transaction(function () use ($debt, $schedule, $timezone): RecurringRule {
            $dates = $this->dates($schedule);
            $rule = $debt->recurringRule()->first();
            if (! $rule) {
                return $this->createRuleAndOccurrences($debt, $schedule, $timezone);
            }
            $rule->forceFill([
                'interval_count' => $schedule['interval_months'], 'starts_on' => $schedule['first_due_on'],
                'ends_on' => $dates[array_key_last($dates)], 'timezone' => $timezone,
                'last_materialized_until' => $dates[array_key_last($dates)],
                'slot_time' => $schedule['reminder_time'],
            ])->save();
            $rule->syncMonthdays([$schedule['monthday']]);
            $existing = PlannedOccurrence::query()->where('recurring_rule_id', $rule->id)->get();
            $stale = $existing->filter(fn (PlannedOccurrence $occurrence): bool => ! in_array($occurrence->occurrence_date->format('Y-m-d'), $dates, true)
                && $occurrence->rescheduled_to === null && ! $occurrence->hasFact())->modelKeys();
            if ($stale !== []) {
                PlannedOccurrence::query()->whereKey($stale)->delete();
            }
            $occurrences = $this->materialize($debt, $rule, $dates);
            $editable = $occurrences->filter(fn (PlannedOccurrence $occurrence): bool => $occurrence->rescheduled_to === null && ! $occurrence->hasFact())->pluck('id');
            FinanceDebtOccurrenceDetail::query()->whereIn('planned_occurrence_id', $editable)->update([
                'debt_name' => $debt->name, 'direction' => $debt->direction,
                'account_id' => $debt->account_id, 'category_id' => $debt->category_id,
                'amount' => $debt->installment_amount, 'currency_code' => $debt->currency_code,
                'updated_at' => now(),
            ]);

            return $rule->fresh(['ruleMonthdays']);
        });
    }

    /** @param list<string> $dates */
    public function materialize(FinanceDebt $debt, RecurringRule $rule, array $dates): Collection
    {
        $now = now();
        PlannedOccurrence::query()->insertOrIgnore(array_map(fn (string $date): array => [
            'user_id' => $debt->user_id,
            'recurring_rule_id' => $rule->id,
            'occurrence_date' => $date,
            'slot' => '',
            'occurrence_time' => $debt->reminder_time,
            'status' => PlannedOccurrence::STATUS_PLANNED,
            'materialized_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $dates));

        $occurrences = PlannedOccurrence::query()->where('recurring_rule_id', $rule->id)
            ->whereIn('occurrence_date', $dates)->orderBy('occurrence_date')->get();
        FinanceDebtOccurrenceDetail::query()->insertOrIgnore($occurrences->map(fn (PlannedOccurrence $occurrence): array => [
            'user_id' => $debt->user_id,
            'planned_occurrence_id' => $occurrence->id,
            'finance_debt_id' => $debt->id,
            'debt_name' => $debt->name,
            'direction' => $debt->direction,
            'account_id' => $debt->account_id,
            'category_id' => $debt->category_id,
            'amount' => $debt->installment_amount,
            'currency_code' => $debt->currency_code,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        return $occurrences;
    }

    private function positive(string $amount, string $field): string
    {
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $amount) || bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([$field => __('messages.finance_positive_money')]);
        }

        return bcadd($amount, '0', 4);
    }
}
