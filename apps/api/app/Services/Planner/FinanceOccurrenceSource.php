<?php

namespace App\Services\Planner;

use App\Contracts\SchedulableSource;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\User;
use App\Support\PlannerEntry;

class FinanceOccurrenceSource implements SchedulableSource
{
    public function name(): string
    {
        return 'finance';
    }

    public function entriesFor(User $user, string $date): array
    {
        return PlannedOccurrence::query()->ownedBy($user)
            ->where(function ($query) use ($date): void {
                $query->where(function ($original) use ($date): void {
                    $original->where('occurrence_date', $date)->whereNull('rescheduled_to');
                })->orWhere('rescheduled_to', $date);
            })
            ->whereIn('recurring_rule_id', RecurringRule::query()->ownedBy($user)
                ->whereIn('owner_type', [RecurringRule::OWNER_FINANCE_RECURRING_OPERATION,
                    RecurringRule::OWNER_FINANCE_DEBT, RecurringRule::OWNER_FINANCE_SAVING_FUND])->select('id'))
            ->with(['recurringRule', 'financeDetail.operation', 'financeOccurrenceFact', 'financeDebtDetail.debt',
                'financeDebtPaymentFact.transactionGroup.reversedBy', 'financeFundDetail.fund',
                'financeFundOccurrenceFact.movement.reversedBy', 'financeFundOccurrenceFact.transactionGroup.reversedBy'])
            ->orderBy('occurrence_time')->orderBy('id')->get()
            ->map(function (PlannedOccurrence $occurrence) use ($date): ?PlannerEntry {
                $kind = match ($occurrence->recurringRule->owner_type) {
                    RecurringRule::OWNER_FINANCE_DEBT => 'debt',
                    RecurringRule::OWNER_FINANCE_SAVING_FUND => 'fund',
                    default => 'recurring_operation',
                };
                $detail = match ($kind) {
                    'debt' => $occurrence->financeDebtDetail,
                    'fund' => $occurrence->financeFundDetail, default => $occurrence->financeDetail
                };
                $owner = match ($kind) {
                    'debt' => $detail?->debt, 'fund' => $detail?->fund,
                    default => $detail?->operation
                };
                if (! $detail || ! $owner || ($occurrence->status === PlannedOccurrence::STATUS_PLANNED
                    && (! $owner->is_active || $owner->is_archived))) {
                    return null;
                }
                $status = $occurrence->status;
                if ($kind === 'debt' && $occurrence->financeDebtPaymentFact?->transactionGroup?->reversedBy === null
                    && $occurrence->financeDebtPaymentFact !== null) {
                    $status = PlannedOccurrence::STATUS_DONE;
                }
                if ($kind === 'fund' && ! $detail->complete) {
                    $status = 'unavailable';
                }
                $accepted = $occurrence->financeOccurrenceFact !== null
                    || ($kind === 'debt' && $occurrence->financeDebtPaymentFact !== null
                        && $occurrence->financeDebtPaymentFact->transactionGroup->reversedBy === null)
                    || ($kind === 'fund' && $occurrence->financeFundOccurrenceFact !== null);
                $name = match ($kind) {
                    'debt' => $detail->debt_name, 'fund' => $detail->fund_name,
                    default => $detail->operation_name
                };
                $direction = match ($kind) {
                    'debt' => $detail->direction === 'owe' ? 'expense' : 'income',
                    'fund' => 'allocation', default => $detail->direction
                };

                return new PlannerEntry(
                    source: $this->name(),
                    sourceId: $occurrence->id,
                    title: $name,
                    time: $occurrence->occurrence_time ? substr((string) $occurrence->occurrence_time, 0, 5) : null,
                    status: $status,
                    actions: $accepted || ($kind === 'fund' && ! $detail->complete) ? [] : ['actualize', 'skip', 'reschedule'],
                    meta: [
                        'kind' => $kind, 'owner_id' => $owner->id,
                        'operation_id' => $kind === 'recurring_operation' ? $owner->id : null,
                        'direction' => $direction,
                        'amount' => $detail->amount === null ? null : (string) $detail->amount,
                        'currency' => $detail->currency_code,
                        'mandatory' => $kind === 'debt' || ($kind === 'fund' && $detail->fund_type === 'emergency')
                            || ($kind === 'recurring_operation' && $detail->is_mandatory),
                        'occurrence_date' => $occurrence->occurrence_date->format('Y-m-d'),
                        'rescheduled_to' => $occurrence->rescheduled_to?->format('Y-m-d'),
                        'action_url' => '/finance?tab='.match ($kind) {
                            'debt' => 'debts', 'fund' => 'funds',
                            default => 'plans'
                        }.'&month='.substr($date, 0, 7).'&occurrence='.$occurrence->id,
                    ],
                );
            })->filter()->values()->all();
    }
}
