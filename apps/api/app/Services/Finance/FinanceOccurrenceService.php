<?php

namespace App\Services\Finance;

use App\Models\FinanceDebt;
use App\Models\FinanceDebtPaymentFact;
use App\Models\FinanceFundOccurrenceFact;
use App\Models\FinanceOccurrenceDetail;
use App\Models\FinanceOccurrenceFact;
use App\Models\FinanceSavingFund;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceOccurrenceService
{
    public function __construct(
        private readonly FinanceLedgerService $ledger,
        private readonly FinanceDebtPaymentService $debtPayments,
        private readonly FinanceFundMovementService $fundMovements,
    ) {}

    /** @return array{FinanceOccurrenceFact,bool} */
    public function setOutcome(
        User $user,
        PlannedOccurrence $occurrence,
        string $outcome,
    ): array {
        abort_unless($occurrence->isOwnedBy($user), 404);
        if (! in_array($outcome, [FinanceOccurrenceFact::OUTCOME_ACTUAL, FinanceOccurrenceFact::OUTCOME_SKIPPED], true)) {
            throw ValidationException::withMessages(['outcome' => __('messages.finance_occurrence_outcome_invalid')]);
        }
        $occurrence->loadMissing('recurringRule');
        if ($occurrence->recurringRule?->owner_type === RecurringRule::OWNER_FINANCE_DEBT) {
            return $this->setDebtOutcome($user, $occurrence, $outcome);
        }
        if ($occurrence->recurringRule?->owner_type === RecurringRule::OWNER_FINANCE_SAVING_FUND) {
            return $this->setFundOutcome($user, $occurrence, $outcome);
        }

        try {
            return DB::transaction(function () use ($user, $occurrence, $outcome): array {
                $locked = PlannedOccurrence::query()->ownedBy($user)->whereKey($occurrence->id)
                    ->with('recurringRule')->lockForUpdate()->firstOrFail();
                abort_unless(
                    $locked->recurringRule?->owner_type === RecurringRule::OWNER_FINANCE_RECURRING_OPERATION,
                    404,
                );
                $detail = FinanceOccurrenceDetail::query()->ownedBy($user)
                    ->where('planned_occurrence_id', $locked->id)->lockForUpdate()->firstOrFail();
                $existing = FinanceOccurrenceFact::query()->ownedBy($user)
                    ->where('planned_occurrence_id', $locked->id)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->outcome !== $outcome) {
                        $this->conflict();
                    }

                    return [$existing->fresh('transactionGroup'), false];
                }

                $effective = $locked->rescheduled_to?->format('Y-m-d')
                    ?? $locked->occurrence_date->format('Y-m-d');
                $groupId = null;
                if ($outcome === FinanceOccurrenceFact::OUTCOME_ACTUAL) {
                    $today = CarbonImmutable::now($user->calendarTimezone())->toDateString();
                    if ($effective > $today) {
                        throw ValidationException::withMessages([
                            'outcome' => __('messages.finance_occurrence_future_actual'),
                        ]);
                    }
                    [$group] = $this->ledger->postActual($user, [
                        'idempotency_key' => 'finance-occurrence:'.$locked->id,
                        'kind' => $detail->direction,
                        'account_id' => $detail->account_id,
                        'category_id' => $detail->category_id,
                        'amount' => $detail->amount,
                        'occurred_on' => $effective,
                        'note' => $detail->operation_name,
                        'tag' => 'recurring',
                    ]);
                    $groupId = $group->id;
                }
                $fact = FinanceOccurrenceFact::query()->create([
                    'user_id' => $user->id,
                    'planned_occurrence_id' => $locked->id,
                    'outcome' => $outcome,
                    'transaction_group_id' => $groupId,
                    'occurred_on' => $outcome === FinanceOccurrenceFact::OUTCOME_ACTUAL ? $effective : null,
                ]);
                $locked->forceFill([
                    'status' => $outcome === FinanceOccurrenceFact::OUTCOME_SKIPPED
                        ? PlannedOccurrence::STATUS_SKIPPED : PlannedOccurrence::STATUS_DONE,
                    'finance_occurrence_fact_id' => $fact->id,
                ])->save();

                return [$fact->fresh('transactionGroup'), true];
            }, 3);
        } catch (QueryException $exception) {
            $fact = FinanceOccurrenceFact::query()->ownedBy($user)
                ->where('planned_occurrence_id', $occurrence->id)->first();
            if ($fact && $fact->outcome === $outcome) {
                return [$fact->load('transactionGroup'), false];
            }

            throw $exception;
        }
    }

    public function clearOutcome(User $user, PlannedOccurrence $occurrence): PlannedOccurrence
    {
        abort_unless($occurrence->isOwnedBy($user), 404);

        $occurrence->loadMissing('recurringRule');
        if ($occurrence->recurringRule?->owner_type === RecurringRule::OWNER_FINANCE_DEBT) {
            return $this->clearDebtOutcome($user, $occurrence);
        }
        if ($occurrence->recurringRule?->owner_type === RecurringRule::OWNER_FINANCE_SAVING_FUND) {
            return $this->clearFundOutcome($user, $occurrence);
        }

        return DB::transaction(function () use ($user, $occurrence): PlannedOccurrence {
            $locked = PlannedOccurrence::query()->ownedBy($user)->whereKey($occurrence->id)
                ->with('recurringRule')->lockForUpdate()->firstOrFail();
            abort_unless(
                $locked->recurringRule?->owner_type === RecurringRule::OWNER_FINANCE_RECURRING_OPERATION,
                404,
            );
            $fact = FinanceOccurrenceFact::query()->ownedBy($user)
                ->where('planned_occurrence_id', $locked->id)->lockForUpdate()->firstOrFail();
            if ($fact->outcome === FinanceOccurrenceFact::OUTCOME_ACTUAL) {
                $this->conflict(__('messages.finance_occurrence_actual_clear'));
            }
            $locked->forceFill([
                'status' => PlannedOccurrence::STATUS_PLANNED,
                'finance_occurrence_fact_id' => null,
            ])->save();
            $fact->delete();

            return $locked->fresh();
        }, 3);
    }

    /** @return array{object,bool} */
    private function setDebtOutcome(User $user, PlannedOccurrence $occurrence, string $outcome): array
    {
        return DB::transaction(function () use ($user, $occurrence, $outcome): array {
            $locked = PlannedOccurrence::query()->ownedBy($user)->whereKey($occurrence->id)
                ->with(['recurringRule', 'financeDebtDetail', 'financeOccurrenceFact',
                    'financeDebtPaymentFact.transactionGroup.reversedBy'])->lockForUpdate()->firstOrFail();
            abort_unless($locked->recurringRule->owner_type === RecurringRule::OWNER_FINANCE_DEBT, 404);
            if ($outcome === FinanceOccurrenceFact::OUTCOME_ACTUAL
                && $locked->financeDebtPaymentFact
                && $locked->financeDebtPaymentFact->transactionGroup->reversedBy === null) {
                return [$locked->financeDebtPaymentFact, false];
            }
            if ($outcome === FinanceOccurrenceFact::OUTCOME_SKIPPED) {
                if ($locked->financeDebtPaymentFact && $locked->financeDebtPaymentFact->transactionGroup->reversedBy === null) {
                    $this->conflict();
                }
                if ($locked->financeOccurrenceFact) {
                    return [$locked->financeOccurrenceFact, false];
                }
                $fact = FinanceOccurrenceFact::query()->create([
                    'user_id' => $user->id, 'planned_occurrence_id' => $locked->id,
                    'outcome' => FinanceOccurrenceFact::OUTCOME_SKIPPED, 'transaction_group_id' => null,
                    'occurred_on' => null,
                ]);
                $locked->forceFill(['status' => PlannedOccurrence::STATUS_SKIPPED,
                    'finance_occurrence_fact_id' => $fact->id])->save();

                return [$fact, true];
            }
            if ($locked->financeOccurrenceFact) {
                $this->conflict();
            }
            $detail = $locked->financeDebtDetail;
            $effective = $locked->rescheduled_to?->format('Y-m-d') ?? $locked->occurrence_date->format('Y-m-d');
            if ($effective > CarbonImmutable::now($user->calendarTimezone())->toDateString()) {
                throw ValidationException::withMessages(['outcome' => __('messages.finance_occurrence_future_actual')]);
            }
            $debt = FinanceDebt::query()->ownedBy($user)->findOrFail($detail->finance_debt_id);
            $attempt = FinanceDebtPaymentFact::query()->ownedBy($user)
                ->where('planned_occurrence_id', $locked->id)->count() + 1;

            return $this->debtPayments->pay($user, $debt, [
                'planned_occurrence_id' => $locked->id, 'amount' => (string) $detail->amount,
                'account_id' => $detail->account_id, 'category_id' => $detail->category_id,
                'occurred_on' => $effective,
                'idempotency_key' => 'finance-debt-occurrence:'.$locked->id.':'.$attempt,
                'note' => $detail->debt_name,
            ]);
        }, 3);
    }

    /** @return array{object,bool} */
    private function setFundOutcome(User $user, PlannedOccurrence $occurrence, string $outcome): array
    {
        return DB::transaction(function () use ($user, $occurrence, $outcome): array {
            $locked = PlannedOccurrence::query()->ownedBy($user)->whereKey($occurrence->id)
                ->with(['recurringRule', 'financeFundDetail', 'financeFundOccurrenceFact'])->lockForUpdate()->firstOrFail();
            abort_unless($locked->recurringRule->owner_type === RecurringRule::OWNER_FINANCE_SAVING_FUND, 404);
            if ($locked->financeFundOccurrenceFact) {
                if ($locked->financeFundOccurrenceFact->outcome !== $outcome) {
                    $this->conflict();
                }

                return [$locked->financeFundOccurrenceFact, false];
            }
            $detail = $locked->financeFundDetail;
            $movement = null;
            if ($outcome === FinanceOccurrenceFact::OUTCOME_ACTUAL) {
                if (! $detail->complete || $detail->amount === null) {
                    throw ValidationException::withMessages(['outcome' => __('messages.finance_fund_occurrence_unavailable')]);
                }
                $effective = $locked->rescheduled_to?->format('Y-m-d') ?? $locked->occurrence_date->format('Y-m-d');
                if ($effective > CarbonImmutable::now($user->calendarTimezone())->toDateString()) {
                    throw ValidationException::withMessages(['outcome' => __('messages.finance_occurrence_future_actual')]);
                }
                $fund = FinanceSavingFund::query()->ownedBy($user)->findOrFail($detail->finance_saving_fund_id);
                [$movement] = $this->fundMovements->move($user, $fund, [
                    'action' => 'top_up', 'amount' => (string) $detail->amount,
                    'counterparty_account_id' => $detail->funding_account_id, 'occurred_on' => $effective,
                    'idempotency_key' => 'finance-fund-occurrence:'.$locked->id, 'note' => $detail->fund_name,
                ]);
            }
            $fact = FinanceFundOccurrenceFact::query()->create([
                'user_id' => $user->id, 'planned_occurrence_id' => $locked->id, 'outcome' => $outcome,
                'finance_fund_movement_id' => $detail->storage_mode === 'virtual' ? $movement?->id : null,
                'transaction_group_id' => $detail->storage_mode === 'linked_account' ? $movement?->transaction_group_id : null,
                'occurred_on' => $movement?->occurred_on,
            ]);
            $locked->forceFill(['status' => $outcome === FinanceOccurrenceFact::OUTCOME_SKIPPED
                ? PlannedOccurrence::STATUS_SKIPPED : PlannedOccurrence::STATUS_DONE,
                'finance_fund_occurrence_fact_id' => $fact->id])->save();

            return [$fact, true];
        }, 3);
    }

    private function clearDebtOutcome(User $user, PlannedOccurrence $occurrence): PlannedOccurrence
    {
        return DB::transaction(function () use ($user, $occurrence): PlannedOccurrence {
            $locked = PlannedOccurrence::query()->ownedBy($user)->whereKey($occurrence->id)
                ->with(['financeOccurrenceFact', 'financeDebtPaymentFact.transactionGroup.reversedBy'])->lockForUpdate()->firstOrFail();
            if ($locked->financeDebtPaymentFact) {
                $this->conflict(__('messages.finance_occurrence_actual_clear'));
            }
            $fact = $locked->financeOccurrenceFact;
            if (! $fact || $fact->outcome !== FinanceOccurrenceFact::OUTCOME_SKIPPED) {
                $this->conflict();
            }
            $locked->forceFill(['status' => PlannedOccurrence::STATUS_PLANNED, 'finance_occurrence_fact_id' => null])->save();
            $fact->delete();

            return $locked->fresh();
        });
    }

    private function clearFundOutcome(User $user, PlannedOccurrence $occurrence): PlannedOccurrence
    {
        return DB::transaction(function () use ($user, $occurrence): PlannedOccurrence {
            $locked = PlannedOccurrence::query()->ownedBy($user)->whereKey($occurrence->id)
                ->with('financeFundOccurrenceFact')->lockForUpdate()->firstOrFail();
            $fact = $locked->financeFundOccurrenceFact;
            if (! $fact || $fact->outcome !== FinanceFundOccurrenceFact::OUTCOME_SKIPPED) {
                $this->conflict(__('messages.finance_occurrence_actual_clear'));
            }
            $locked->forceFill(['status' => PlannedOccurrence::STATUS_PLANNED,
                'finance_fund_occurrence_fact_id' => null])->save();
            $fact->delete();

            return $locked->fresh();
        });
    }

    private function conflict(?string $message = null): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message ?? __('messages.finance_occurrence_conflict'),
        ], 409));
    }
}
