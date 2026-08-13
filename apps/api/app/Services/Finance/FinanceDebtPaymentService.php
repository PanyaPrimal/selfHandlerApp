<?php

namespace App\Services\Finance;

use App\Models\FinanceDebt;
use App\Models\FinanceDebtPaymentFact;
use App\Models\FinanceTransactionGroup;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\User;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceDebtPaymentService
{
    public function __construct(
        private readonly FinanceLedgerService $ledger,
        private readonly FinanceDebtProjectionService $projections,
    ) {}

    /** @param array<string, mixed> $data @return array{FinanceDebtPaymentFact, bool} */
    public function pay(User $user, FinanceDebt $debt, array $data): array
    {
        abort_unless($debt->isOwnedBy($user), 404);

        return DB::transaction(function () use ($user, $debt, $data): array {
            $locked = FinanceDebt::query()->ownedBy($user)->whereKey($debt->id)->lockForUpdate()->firstOrFail();
            if (! $locked->is_active || $locked->is_archived) {
                throw ValidationException::withMessages(['debt' => __('messages.finance_debt_inactive')]);
            }
            $retryGroup = FinanceTransactionGroup::query()->ownedBy($user)
                ->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($retryGroup) {
                // The ledger remains the payload-hash authority. Calling it before
                // the remaining-principal check lets an exact retry observe the
                // original fact rather than treating its own payment as an overpay.
                [$group] = $this->ledger->postActual($user, [
                    'kind' => $locked->direction === 'owe' ? 'expense' : 'income',
                    'account_id' => $data['account_id'], 'category_id' => $data['category_id'],
                    'amount' => $data['amount'], 'occurred_on' => $data['occurred_on'],
                    'idempotency_key' => $data['idempotency_key'], 'note' => $data['note'] ?? null, 'tag' => null,
                ]);
                $fact = FinanceDebtPaymentFact::query()->where('transaction_group_id', $group->id)
                    ->where('finance_debt_id', $locked->id)->firstOrFail();

                return [$fact->load('transactionGroup.reversedBy'), false];
            }
            $amount = Money::of((string) $data['amount'], $locked->currency_code);
            if (bccomp($amount->amount(), '0', 4) <= 0) {
                throw ValidationException::withMessages(['amount' => __('messages.finance_positive_money')]);
            }
            $projection = $this->projections->project($user, $locked);
            if (bccomp($amount->amount(), $projection['remaining_amount'], 4) > 0) {
                throw ValidationException::withMessages(['amount' => __('messages.finance_debt_payment_exceeds_remaining')]);
            }

            $occurrence = $this->occurrence($user, $locked, $data['planned_occurrence_id'] ?? null, $amount->amount());
            [$group, $created] = $this->ledger->postActual($user, [
                'kind' => $locked->direction === 'owe' ? 'expense' : 'income',
                'account_id' => $data['account_id'],
                'category_id' => $data['category_id'],
                'amount' => $amount->amount(),
                'occurred_on' => $data['occurred_on'],
                'idempotency_key' => $data['idempotency_key'],
                'note' => $data['note'] ?? null,
                'tag' => null,
            ]);
            $existing = FinanceDebtPaymentFact::query()->where('transaction_group_id', $group->id)->first();
            if ($existing) {
                return [$existing->load('transactionGroup.reversedBy'), false];
            }

            $fact = FinanceDebtPaymentFact::query()->create([
                'user_id' => $user->id,
                'finance_debt_id' => $locked->id,
                'planned_occurrence_id' => $occurrence?->id,
                'transaction_group_id' => $group->id,
                'principal_amount' => $amount->amount(),
                'currency_code' => $locked->currency_code,
                'occurred_on' => $data['occurred_on'],
            ]);
            if ($occurrence) {
                $occurrence->forceFill([
                    'finance_debt_payment_fact_id' => $fact->id,
                    'status' => PlannedOccurrence::STATUS_DONE,
                ])->save();
            }

            return [$fact->load('transactionGroup.reversedBy'), $created];
        }, 3);
    }

    private function occurrence(User $user, FinanceDebt $debt, mixed $id, string $amount): ?PlannedOccurrence
    {
        if ($debt->repayment_mode === 'flexible') {
            if ($id !== null) {
                abort(404);
            }

            return null;
        }
        if ($id === null) {
            throw ValidationException::withMessages(['planned_occurrence_id' => __('messages.finance_debt_occurrence_required')]);
        }
        $occurrence = PlannedOccurrence::query()->ownedBy($user)->whereKey($id)
            ->whereHas('recurringRule', fn ($query) => $query
                ->where('owner_type', RecurringRule::OWNER_FINANCE_DEBT)->where('owner_id', $debt->id))
            ->with(['financeDebtDetail', 'financeOccurrenceFact', 'financeDebtPaymentFact.transactionGroup.reversedBy'])->firstOrFail();
        if (bccomp((string) $occurrence->financeDebtDetail->amount, $amount, 4) !== 0
            || $occurrence->financeOccurrenceFact !== null
            || ($occurrence->financeDebtPaymentFact && ! $occurrence->financeDebtPaymentFact->transactionGroup->reversedBy)) {
            throw ValidationException::withMessages(['planned_occurrence_id' => __('messages.finance_debt_occurrence_invalid')]);
        }

        return $occurrence;
    }
}
