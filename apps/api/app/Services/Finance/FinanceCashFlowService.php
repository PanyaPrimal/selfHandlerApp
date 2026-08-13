<?php

namespace App\Services\Finance;

use App\Models\FinanceRecurringOperation;
use App\Models\PlannedOccurrence;
use App\Models\User;
use App\Services\RecurringRuleExpander;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class FinanceCashFlowService
{
    public function __construct(
        private readonly FinanceExchangeRateService $exchangeRates,
        private readonly RecurringRuleExpander $expander,
    ) {}

    /** @return array<string,mixed> */
    public function build(User $user, string $month): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw ValidationException::withMessages(['month' => __('messages.finance_month_invalid')]);
        }
        $timezone = $user->calendarTimezone();
        $fromDate = CarbonImmutable::parse($month.'-01', $timezone)->startOfMonth();
        $toDate = $fromDate->endOfMonth();
        $from = $fromDate->toDateString();
        $to = $toDate->toDateString();
        $base = $user->ensureProfile()->base_currency;
        $operations = FinanceRecurringOperation::query()->ownedBy($user)
            ->where('is_active', true)->where('is_archived', false)
            ->with(['recurringRule.ruleMonthdays'])->orderBy('id')->get();
        $rules = $operations->pluck('recurringRule')->filter();
        $existing = PlannedOccurrence::query()->ownedBy($user)
            ->whereIn('recurring_rule_id', $rules->pluck('id'))
            ->where(function ($query) use ($from, $to): void {
                $query->whereBetween('occurrence_date', [$from, $to])
                    ->orWhereBetween('rescheduled_to', [$from, $to]);
            })
            ->with(['financeOccurrenceFact', 'financeDetail'])
            ->get();
        $byIdentity = $existing->keyBy(fn (PlannedOccurrence $occurrence): string => $occurrence->recurring_rule_id.':'.$occurrence->occurrence_date->format('Y-m-d'));
        $rows = [];
        $usedOccurrenceIds = [];
        foreach ($operations as $operation) {
            $rule = $operation->recurringRule;
            if (! $rule) {
                continue;
            }
            foreach ($this->expander->datesBetween($rule, $from, $to) as $plannedOn) {
                $occurrence = $byIdentity->get($rule->id.':'.$plannedOn);
                $effective = $occurrence?->rescheduled_to?->format('Y-m-d') ?? $plannedOn;
                if ($effective < $from || $effective > $to) {
                    continue;
                }
                if ($occurrence) {
                    $usedOccurrenceIds[] = $occurrence->id;
                }
                $detail = $occurrence?->financeDetail;
                $rows[] = [
                    'date' => $effective,
                    'direction' => $detail?->direction ?? $operation->direction,
                    'amount' => (string) ($detail?->amount ?? $operation->amount),
                    'currency' => $detail?->currency_code ?? $operation->currency_code,
                    'mandatory' => (bool) ($detail?->is_mandatory ?? $operation->is_mandatory),
                    'status' => $occurrence?->financeOccurrenceFact?->outcome ?? 'planned',
                ];
            }
        }
        foreach ($existing->whereNotIn('id', $usedOccurrenceIds)
            ->filter(fn (PlannedOccurrence $occurrence): bool => $occurrence->rescheduled_to !== null
                && $occurrence->rescheduled_to->format('Y-m-d') >= $from
                && $occurrence->rescheduled_to->format('Y-m-d') <= $to) as $occurrence) {
            $detail = $occurrence->financeDetail;
            if (! $detail) {
                continue;
            }
            $rows[] = [
                'date' => $occurrence->rescheduled_to->format('Y-m-d'),
                'direction' => $detail->direction,
                'amount' => (string) $detail->amount,
                'currency' => $detail->currency_code,
                'mandatory' => (bool) $detail->is_mandatory,
                'status' => $occurrence->financeOccurrenceFact?->outcome ?? 'planned',
            ];
        }

        $catalog = $this->exchangeRates->catalog($user, $to);
        $totals = ['planned_income' => '0.0000', 'mandatory_expense' => '0.0000',
            'discretionary_expense' => '0.0000'];
        $counts = ['total' => 0, 'planned' => 0, 'actual' => 0, 'skipped' => 0,
            'income' => 0, 'mandatory_expense' => 0, 'discretionary_expense' => 0];
        $missing = [];
        $conversions = [];
        foreach ($rows as $row) {
            $bucket = $row['direction'] === 'income' ? 'income'
                : ($row['mandatory'] ? 'mandatory_expense' : 'discretionary_expense');
            $counts['total']++;
            $counts[$row['status']]++;
            $counts[$bucket]++;
            if ($row['status'] === 'skipped' || bccomp($row['amount'], '0', 4) === 0) {
                continue;
            }
            $lookup = $this->exchangeRates->lookup($user, $row['currency'], $base, $row['date'], $catalog);
            if (! $lookup) {
                $missing[] = $row['currency'];

                continue;
            }
            $converted = $this->exchangeRates->convert($row['amount'], $row['currency'], $base, $lookup);
            $totalKey = $bucket === 'income' ? 'planned_income' : $bucket;
            $totals[$totalKey] = bcadd($totals[$totalKey], $converted, 4);
            $conversions[] = [
                'on' => $row['date'],
                'from_currency' => $row['currency'],
                'source_amount' => $row['amount'],
                'converted_amount' => $converted,
                'rate' => $lookup['rate'],
                'rate_date' => $lookup['date'],
                'rate_direction' => $lookup['direction'],
            ];
        }
        $missing = array_values(array_unique($missing));
        sort($missing);
        $complete = $missing === [];

        return [
            'month' => $month,
            'from' => $from,
            'to' => $to,
            'base_currency' => $base,
            'complete' => $complete,
            'planned_income' => $complete ? $totals['planned_income'] : null,
            'mandatory_expense' => $complete ? $totals['mandatory_expense'] : null,
            'discretionary_expense' => $complete ? $totals['discretionary_expense'] : null,
            'free_cash_flow' => $complete
                ? bcsub($totals['planned_income'], $totals['mandatory_expense'], 4) : null,
            'missing_currencies' => $missing,
            'conversions' => $conversions,
            'counts' => $counts,
        ];
    }
}
