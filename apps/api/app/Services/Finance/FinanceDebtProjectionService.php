<?php

namespace App\Services\Finance;

use App\Models\FinanceDebt;
use App\Models\FinanceDebtPaymentFact;
use App\Models\PlannedOccurrence;
use App\Models\User;
use Carbon\CarbonImmutable;

class FinanceDebtProjectionService
{
    /** @return array<string, mixed> */
    public function project(User $user, FinanceDebt $debt): array
    {
        abort_unless($debt->isOwnedBy($user), 404);
        $debt->loadMissing([
            'counterparty',
            'paymentFacts.transactionGroup.reversedBy',
            'recurringRule.occurrences.financeDebtDetail',
            'recurringRule.occurrences.financeDebtPaymentFact.transactionGroup.reversedBy',
        ]);
        $payments = $debt->paymentFacts->sortByDesc('id')->take(500)->values();
        $active = $payments->filter(fn (FinanceDebtPaymentFact $fact): bool => $fact->transactionGroup->reversedBy === null);
        $paid = $active->reduce(fn (string $sum, FinanceDebtPaymentFact $fact): string => bcadd($sum, (string) $fact->principal_amount, 4), '0.0000');
        $remaining = bcsub((string) $debt->original_amount, $paid, 4);
        if (bccomp($remaining, '0', 4) < 0) {
            $remaining = '0.0000';
        }
        $today = CarbonImmutable::now($user->calendarTimezone())->toDateString();

        $occurrences = collect();
        if ($debt->recurringRule) {
            $occurrences = $debt->recurringRule->occurrences->sortBy('occurrence_date')->values()->map(
                function (PlannedOccurrence $occurrence) use ($today): array {
                    $fact = $occurrence->financeDebtPaymentFact;
                    $payment = $fact ? $this->payment($fact) : null;
                    $paid = $fact && ! $payment['reversed'];
                    $due = $occurrence->rescheduled_to?->format('Y-m-d') ?? $occurrence->occurrence_date->format('Y-m-d');

                    return [
                        'id' => $occurrence->id,
                        'due_on' => $due,
                        'original_due_on' => $occurrence->occurrence_date->format('Y-m-d'),
                        'amount' => (string) $occurrence->financeDebtDetail->amount,
                        'currency' => $occurrence->financeDebtDetail->currency_code,
                        'status' => $paid ? 'paid' : ($due < $today ? 'overdue' : 'scheduled'),
                        'reminder_time' => $occurrence->occurrence_time,
                        'latest_payment' => $payment,
                    ];
                },
            );
        }
        $counts = [
            'scheduled' => $occurrences->where('status', 'scheduled')->count(),
            'paid' => $occurrences->where('status', 'paid')->count(),
            'overdue' => $occurrences->where('status', 'overdue')->count(),
        ];
        $state = bccomp($remaining, '0', 4) === 0 ? 'settled'
            : (($debt->deadline?->format('Y-m-d') < $today && $debt->deadline !== null) || $counts['overdue'] > 0 ? 'overdue' : 'active');

        return [
            'id' => $debt->id,
            'name' => $debt->name,
            'counterparty' => [
                'id' => $debt->counterparty->id, 'name' => $debt->counterparty->name,
                'kind' => $debt->counterparty->kind, 'note' => $debt->counterparty->note,
                'archived' => $debt->counterparty->is_archived,
                'created_at' => $debt->counterparty->created_at?->toISOString(),
                'updated_at' => $debt->counterparty->updated_at?->toISOString(),
            ],
            'direction' => $debt->direction,
            'repayment_mode' => $debt->repayment_mode,
            'original_amount' => (string) $debt->original_amount,
            'paid_amount' => $paid,
            'remaining_amount' => $remaining,
            'currency' => $debt->currency_code,
            'progress' => (float) bcdiv($paid, (string) $debt->original_amount, 8),
            'originated_on' => $debt->originated_on->format('Y-m-d'),
            'deadline' => $debt->deadline?->format('Y-m-d'),
            'state' => $state,
            'account_id' => $debt->account_id,
            'category_id' => $debt->category_id,
            'purchase_item_id' => $debt->purchase_item_id,
            'active' => $debt->is_active,
            'archived' => $debt->is_archived,
            'schedule' => $debt->repayment_mode === 'fixed' ? [
                'installment_amount' => (string) $debt->installment_amount,
                'installment_count' => $debt->installment_count,
                'interval_months' => $debt->interval_months,
                'monthday' => $debt->monthday,
                'first_due_on' => $debt->first_due_on?->format('Y-m-d'),
                'reminder_time' => $debt->reminder_time,
            ] : null,
            'occurrences' => $occurrences,
            'payments' => $payments->map(fn (FinanceDebtPaymentFact $fact): array => $this->payment($fact)),
            'counts' => $counts,
            'created_at' => $debt->created_at?->toISOString(),
            'updated_at' => $debt->updated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    public function payment(FinanceDebtPaymentFact $fact): array
    {
        $fact->loadMissing('transactionGroup.reversedBy');

        return [
            'id' => $fact->id,
            'planned_occurrence_id' => $fact->planned_occurrence_id,
            'transaction_public_id' => $fact->transactionGroup->public_id,
            'principal_amount' => (string) $fact->principal_amount,
            'currency' => $fact->currency_code,
            'occurred_on' => $fact->occurred_on->format('Y-m-d'),
            'reversed' => $fact->transactionGroup->reversedBy !== null,
        ];
    }
}
