<?php

namespace App\Services\Finance;

use App\Models\FinanceOccurrenceDetail;
use App\Models\FinanceOccurrenceFact;
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
    public function __construct(private readonly FinanceLedgerService $ledger) {}

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

    private function conflict(?string $message = null): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message ?? __('messages.finance_occurrence_conflict'),
        ], 409));
    }
}
