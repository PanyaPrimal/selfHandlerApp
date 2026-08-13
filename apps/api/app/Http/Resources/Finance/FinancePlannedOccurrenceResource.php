<?php

namespace App\Http\Resources\Finance;

use App\Models\FinanceFundOccurrenceFact;
use App\Models\RecurringRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinancePlannedOccurrenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $kind = match ($this->recurringRule->owner_type) {
            RecurringRule::OWNER_FINANCE_DEBT => 'debt',
            RecurringRule::OWNER_FINANCE_SAVING_FUND => 'fund',
            default => 'recurring_operation',
        };
        $detail = match ($kind) {
            'debt' => $this->financeDebtDetail,
            'fund' => $this->financeFundDetail,
            default => $this->financeDetail,
        };
        [$outcome, $transactionId, $occurredOn, $createdAt] = $this->outcome($kind);
        $effective = $this->rescheduled_to?->format('Y-m-d') ?? $this->occurrence_date->format('Y-m-d');
        $direction = match ($kind) {
            'debt' => $detail->direction === 'owe' ? 'expense' : 'income',
            'fund' => 'allocation',
            default => $detail->direction,
        };
        $amount = $detail->amount === null ? null : (string) $detail->amount;
        $unavailable = $kind === 'fund' && ! $detail->complete;
        $status = $unavailable ? 'unavailable' : ($outcome ?? ($kind !== 'recurring_operation'
            && $effective < CarbonImmutable::now($request->user()->calendarTimezone())->toDateString()
            ? 'overdue' : 'planned'));
        $result = [
            'id' => $this->id,
            'original_date' => $this->occurrence_date->format('Y-m-d'), 'date' => $effective,
            'time' => $this->occurrence_time ? substr((string) $this->occurrence_time, 0, 5) : null,
            'status' => $status, 'outcome_type' => $outcome, 'transaction_public_id' => $transactionId,
            'outcome' => $outcome ? ['type' => $outcome, 'transaction_id' => $transactionId,
                'occurred_on' => $occurredOn, 'created_at' => $createdAt] : null,
            'context' => ['kind' => $kind, 'owner_id' => $this->recurringRule->owner_id,
                'name' => match ($kind) {
                    'debt' => $detail->debt_name, 'fund' => $detail->fund_name,
                    default => $detail->operation_name
                },
                'direction' => $direction, 'amount' => $amount, 'currency' => $detail->currency_code,
                'mandatory' => $kind === 'debt' || ($kind === 'fund' && $detail->fund_type === 'emergency')
                    || ($kind === 'recurring_operation' && $detail->is_mandatory),
                'evidence' => $kind === 'fund' ? $detail->calculation_basis : null],
            'action_url' => '/finance?tab='.match ($kind) {
                'debt' => 'debts', 'fund' => 'funds', default => 'planning'
            }
                .'&occurrence='.$this->id,
        ];
        if ($kind === 'recurring_operation') {
            $result += [
                'operation_id' => $detail->finance_recurring_operation_id, 'operation_name' => $detail->operation_name,
                'planned_on' => $this->occurrence_date->format('Y-m-d'), 'effective_on' => $effective,
                'moved' => $this->rescheduled_to !== null,
                'reminder_time' => $this->occurrence_time ? substr((string) $this->occurrence_time, 0, 5) : null,
                'direction' => $detail->direction,
                'account' => ['id' => $detail->account->id, 'name' => $detail->account->name,
                    'archived' => $detail->account->archived_at !== null],
                'category' => ['id' => $detail->category->id, 'parent_id' => $detail->category->parent_id,
                    'label' => $detail->category->displayLabel(), 'archived' => $detail->category->archived_at !== null],
                'amount' => (string) $detail->amount, 'currency' => $detail->currency_code,
                'mandatory' => $detail->is_mandatory,
            ];
        }

        return $result;
    }

    /** @return array{?string,?string,?string,?string} */
    private function outcome(string $kind): array
    {
        if ($kind === 'debt') {
            $payment = $this->financeDebtPaymentFact;
            if ($payment && $payment->transactionGroup->reversedBy === null) {
                return ['actual', $payment->transactionGroup->public_id, $payment->occurred_on->format('Y-m-d'), $payment->created_at?->toISOString()];
            }
            $skip = $this->financeOccurrenceFact;

            return $skip ? ['skipped', null, null, $skip->created_at?->toISOString()] : [null, null, null, null];
        }
        if ($kind === 'fund') {
            $fact = $this->financeFundOccurrenceFact;
            if (! $fact) {
                return [null, null, null, null];
            }
            $active = $fact->outcome === FinanceFundOccurrenceFact::OUTCOME_SKIPPED
                || ($fact->movement && $fact->movement->reversedBy === null)
                || ($fact->transactionGroup && $fact->transactionGroup->reversedBy === null);
            if (! $active) {
                return [null, null, null, null];
            }

            return [$fact->outcome, $fact->transactionGroup?->public_id ?? $fact->movement?->transactionGroup?->public_id,
                $fact->occurred_on?->format('Y-m-d'), $fact->created_at?->toISOString()];
        }
        $fact = $this->financeOccurrenceFact;

        return $fact ? [$fact->outcome, $fact->transactionGroup?->public_id, $fact->occurred_on?->format('Y-m-d'),
            $fact->created_at?->toISOString()] : [null, null, null, null];
    }
}
