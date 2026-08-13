<?php

namespace App\Services\Finance;

use App\Models\FinanceDebt;
use App\Models\FinanceGoalDetail;
use App\Models\FinanceSavingFund;
use App\Models\Goal;
use App\Models\GoalMilestone;
use App\Models\User;
use App\ValueObjects\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceGoalService
{
    public function __construct(
        private readonly FinanceDebtProjectionService $debts,
        private readonly FinanceFundProjectionService $funds,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): Goal
    {
        return DB::transaction(function () use ($user, $data): Goal {
            [$kind, $target, $currency] = $this->target($user, $data['kind'], $data['saving_fund_id'], $data['debt_id']);
            $this->assertNoDuplicate($user, $kind, $target->id);
            $goal = new Goal(['user_id' => $user->id, 'type' => Goal::TYPE_FINANCE]);
            $goal->applyLifecycle([
                'name' => trim((string) $data['name']), 'description' => $data['description'],
                'target_date' => $data['target_date'], 'status' => 'active', 'is_archived' => false,
            ]);
            $goal->save();
            FinanceGoalDetail::query()->create([
                'user_id' => $user->id, 'goal_id' => $goal->id, 'kind' => $kind,
                'finance_saving_fund_id' => $kind === 'save' ? $target->id : null,
                'finance_debt_id' => $kind === 'pay_off' ? $target->id : null,
                'currency_code' => $currency,
            ]);
            $this->replaceMilestones($user, $goal, $data['milestones'], $currency);

            return $goal->fresh(['financeDetail', 'milestones']);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, Goal $goal, array $data): Goal
    {
        abort_unless($goal->isOwnedBy($user) && $goal->type === Goal::TYPE_FINANCE, 404);

        return DB::transaction(function () use ($user, $goal, $data): Goal {
            $goal->applyLifecycle(array_filter([
                'name' => array_key_exists('name', $data) ? trim((string) $data['name']) : null,
                'description' => $data['description'] ?? null, 'target_date' => $data['target_date'] ?? null,
                'status' => $data['status'] ?? null, 'is_archived' => $data['archived'] ?? null,
            ], fn ($value, $key): bool => array_key_exists($key === 'is_archived' ? 'archived' : $key, $data), ARRAY_FILTER_USE_BOTH));
            $goal->save();
            if (array_key_exists('milestones', $data)) {
                $this->replaceMilestones($user, $goal, $data['milestones'], $goal->financeDetail->currency_code);
            }

            return $goal->fresh(['financeDetail', 'milestones']);
        }, 3);
    }

    /** @return array<string, mixed> */
    public function one(User $user, Goal $goal): array
    {
        abort_unless($goal->isOwnedBy($user) && $goal->type === Goal::TYPE_FINANCE, 404);
        $goal->unsetRelations();
        $goal->loadMissing(['financeDetail.savingFund', 'financeDetail.debt', 'milestones']);
        $detail = $goal->financeDetail;
        $projection = $detail->kind === 'save'
            ? $this->funds->project($user, $detail->savingFund, now($user->calendarTimezone())->format('Y-m'))
            : $this->debts->project($user, $detail->debt);

        return $this->serialize($goal, $projection);
    }

    /** @param array<string,mixed> $projection @return array<string,mixed> */
    private function serialize(Goal $goal, array $projection): array
    {
        $detail = $goal->financeDetail;
        if ($detail->kind === 'save') {
            $starting = '0.0000';
            $target = $projection['target_amount'];
            $current = $projection['saved_amount'];
            $remaining = $projection['remaining_amount'];
            $progress = $projection['progress'];
        } else {
            $starting = (string) $detail->debt->original_amount;
            $target = '0.0000';
            $current = $projection['remaining_amount'];
            $remaining = $projection['remaining_amount'];
            $progress = $projection['progress'];
        }
        $milestones = $detail->kind === 'save'
            ? $goal->milestones->sortBy('target_value')
            : $goal->milestones->sortByDesc('target_value');

        return [
            'id' => $goal->id, 'name' => $goal->name, 'description' => $goal->description,
            'type' => Goal::TYPE_FINANCE, 'kind' => $detail->kind,
            'target_date' => $goal->target_date?->format('Y-m-d'), 'status' => $goal->status,
            'archived' => $goal->is_archived, 'currency' => $detail->currency_code,
            'aggregate_id' => $detail->kind === 'save' ? $detail->finance_saving_fund_id : $detail->finance_debt_id,
            'starting_value' => $starting, 'target_value' => $target, 'current_value' => $current,
            'remaining_value' => $remaining, 'progress' => min(1.0, max(0.0, (float) $progress)),
            'milestones' => $milestones->values()->map(fn (GoalMilestone $milestone): array => [
                'id' => $milestone->id, 'target_value' => (string) $milestone->target_value,
                'target_date' => $milestone->target_date?->format('Y-m-d'),
                'achieved' => $detail->kind === 'save'
                    ? bccomp($current, (string) $milestone->target_value, 4) >= 0
                    : bccomp($current, (string) $milestone->target_value, 4) <= 0,
            ])->values(),
            'created_at' => $goal->created_at?->toISOString(), 'updated_at' => $goal->updated_at?->toISOString(),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function list(User $user, bool $archived = false): Collection
    {
        $goals = Goal::query()->ownedBy($user)->where('type', Goal::TYPE_FINANCE)->where('is_archived', $archived)
            ->with([
                'milestones', 'financeDetail.savingFund.account',
                'financeDetail.savingFund.movements.transactionGroup.reversedBy',
                'financeDetail.savingFund.movements.reversedBy', 'financeDetail.debt.counterparty',
                'financeDetail.debt.paymentFacts.transactionGroup.reversedBy',
                'financeDetail.debt.recurringRule.occurrences.financeDebtDetail',
                'financeDetail.debt.recurringRule.occurrences.financeDebtPaymentFact.transactionGroup.reversedBy',
            ])->orderBy('id')->get();
        $funds = $goals->map(fn (Goal $goal) => $goal->financeDetail->savingFund)->filter()->unique('id')->values();
        $debts = $goals->map(fn (Goal $goal) => $goal->financeDetail->debt)->filter()->unique('id')->values();
        $month = now($user->calendarTimezone())->format('Y-m');
        $fundProjections = $this->funds->projectMany($user, $funds, $month);
        $debtProjections = $debts->mapWithKeys(fn (FinanceDebt $debt): array => [
            $debt->id => $this->debts->project($user, $debt),
        ]);

        return $goals->map(function (Goal $goal) use ($fundProjections, $debtProjections): array {
            $detail = $goal->financeDetail;
            $projection = $detail->kind === 'save'
                ? $fundProjections[$detail->finance_saving_fund_id]
                : $debtProjections[$detail->finance_debt_id];

            return $this->serialize($goal, $projection);
        });
    }

    /** @return array{string, FinanceSavingFund|FinanceDebt, string} */
    private function target(User $user, string $kind, mixed $fundId, mixed $debtId): array
    {
        if ($kind === 'save' && $fundId !== null && $debtId === null) {
            $target = FinanceSavingFund::query()->ownedBy($user)->findOrFail($fundId);
        } elseif ($kind === 'pay_off' && $debtId !== null && $fundId === null) {
            $target = FinanceDebt::query()->ownedBy($user)->findOrFail($debtId);
        } else {
            throw ValidationException::withMessages(['kind' => __('messages.finance_goal_target_invalid')]);
        }
        if (! $target->is_active || $target->is_archived || ($kind === 'save' && $target->target_amount === null)) {
            throw ValidationException::withMessages(['kind' => __('messages.finance_goal_target_invalid')]);
        }

        return [$kind, $target, $target->currency_code];
    }

    private function assertNoDuplicate(User $user, string $kind, int $id): void
    {
        $column = $kind === 'save' ? 'finance_saving_fund_id' : 'finance_debt_id';
        if (FinanceGoalDetail::query()->ownedBy($user)->where($column, $id)
            ->whereHas('goal', fn ($query) => $query->where('status', 'active')->where('is_archived', false))->exists()) {
            throw ValidationException::withMessages(['kind' => __('messages.finance_goal_duplicate')]);
        }
    }

    /** @param list<array<string, mixed>> $milestones */
    private function replaceMilestones(User $user, Goal $goal, array $milestones, string $currency): void
    {
        $goal->loadMissing(['financeDetail.savingFund', 'financeDetail.debt']);
        $kind = $goal->financeDetail->kind;
        $limit = $kind === 'save'
            ? (string) $goal->financeDetail->savingFund->target_amount
            : (string) $goal->financeDetail->debt->original_amount;
        $validated = [];
        $previous = null;
        foreach ($milestones as $milestone) {
            $amount = Money::of((string) $milestone['target_value'], $currency);
            $value = $amount->amount();
            $wrongOrder = $previous !== null && ($kind === 'save'
                ? bccomp($value, $previous, 4) <= 0
                : bccomp($value, $previous, 4) >= 0);
            if (bccomp($value, '0', 4) <= 0 || bccomp($value, $limit, 4) > 0 || $wrongOrder) {
                throw ValidationException::withMessages(['milestones' => __('messages.finance_goal_milestone_invalid')]);
            }
            $validated[] = ['target_value' => $value, 'target_date' => $milestone['target_date']];
            $previous = $value;
        }
        GoalMilestone::query()->where('goal_id', $goal->id)->delete();
        foreach ($validated as $milestone) {
            GoalMilestone::query()->create([
                'user_id' => $user->id, 'goal_id' => $goal->id, 'target_value' => $milestone['target_value'],
                'target_date' => $milestone['target_date'],
            ]);
        }
    }
}
