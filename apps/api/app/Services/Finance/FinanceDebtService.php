<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceCounterparty;
use App\Models\FinanceDebt;
use App\Models\FinanceTransactionGroup;
use App\Models\Item;
use App\Models\User;
use App\ValueObjects\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceDebtService
{
    public function __construct(
        private readonly FinanceDebtScheduleService $schedules,
        private readonly FinanceDebtProjectionService $projections,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): FinanceDebt
    {
        return DB::transaction(function () use ($user, $data): FinanceDebt {
            $counterparty = FinanceCounterparty::query()->ownedBy($user)->findOrFail($data['counterparty_id']);
            if ($counterparty->is_archived) {
                throw ValidationException::withMessages(['counterparty_id' => __('messages.finance_counterparty_archived')]);
            }
            $direction = (string) $data['direction'];
            $mode = (string) $data['repayment_mode'];
            if (! in_array($direction, FinanceDebt::DIRECTIONS, true) || ! in_array($mode, FinanceDebt::REPAYMENT_MODES, true)) {
                throw ValidationException::withMessages(['repayment_mode' => __('messages.finance_debt_invalid')]);
            }
            $money = Money::of((string) $data['original_amount'], (string) $data['currency']);
            if (bccomp($money->amount(), '0', 4) <= 0) {
                throw ValidationException::withMessages(['original_amount' => __('messages.finance_positive_money')]);
            }
            $account = $this->account($user, $data['account_id'] ?? null, $money->currency());
            $category = $this->category($user, $data['category_id'] ?? null, $direction === 'owe' ? 'expense' : 'income');
            $purchase = $this->purchase($user, $data['purchase_item_id'] ?? null);
            if ($purchase && ($direction !== 'owe' || $mode !== 'fixed')) {
                throw ValidationException::withMessages(['purchase_item_id' => __('messages.finance_purchase_invalid')]);
            }
            if ($purchase && FinanceTransactionGroup::query()->ownedBy($user)
                ->where('source_type', FinanceTransactionGroup::SOURCE_PURCHASE_ITEM)
                ->where('source_id', $purchase->id)->whereNull('reverses_group_id')
                ->whereDoesntHave('reversedBy')->exists()) {
                throw ValidationException::withMessages(['purchase_item_id' => __('messages.finance_purchase_has_expense')]);
            }
            if ($mode === 'fixed' && (! $account || ! $category || empty($data['schedule']))) {
                throw ValidationException::withMessages(['schedule' => __('messages.finance_debt_schedule_required')]);
            }
            if ($mode === 'flexible' && ! empty($data['schedule'])) {
                throw ValidationException::withMessages(['schedule' => __('messages.finance_debt_schedule_flexible')]);
            }

            $debt = FinanceDebt::query()->create([
                'user_id' => $user->id,
                'finance_counterparty_id' => $counterparty->id,
                'purchase_item_id' => $purchase?->id,
                'name' => trim((string) $data['name']),
                'direction' => $direction,
                'repayment_mode' => $mode,
                'original_amount' => $money->amount(),
                'currency_code' => $money->currency(),
                'originated_on' => $data['originated_on'],
                'deadline' => $data['deadline'] ?? null,
                'account_id' => $account?->id,
                'category_id' => $category?->id,
                'note' => $this->nullableTrim($data['note'] ?? null),
            ]);

            if ($mode === 'fixed') {
                $schedule = $this->schedules->validate($debt, $data['schedule']);
                $debt->forceFill($schedule)->save();
                $this->schedules->createRuleAndOccurrences($debt, $schedule, $user->calendarTimezone());
            }
            if ($purchase) {
                $purchase->applyStatus(Item::STATUS_DONE);
                $purchase->save();
            }

            return $debt->fresh();
        }, 3);
    }

    /** @return array<string, mixed> */
    public function one(User $user, FinanceDebt $debt): array
    {
        $debt->unsetRelations();

        return $this->projections->project($user, $debt);
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, FinanceDebt $debt, array $data): FinanceDebt
    {
        abort_unless($debt->isOwnedBy($user), 404);

        return DB::transaction(function () use ($user, $debt, $data): FinanceDebt {
            $locked = FinanceDebt::query()->ownedBy($user)->whereKey($debt->id)->lockForUpdate()->firstOrFail();
            if (array_key_exists('counterparty_id', $data)) {
                $counterparty = FinanceCounterparty::query()->ownedBy($user)->findOrFail($data['counterparty_id']);
                if ($counterparty->is_archived) {
                    throw ValidationException::withMessages(['counterparty_id' => __('messages.finance_counterparty_archived')]);
                }
                $locked->finance_counterparty_id = $counterparty->id;
            }
            if (array_key_exists('account_id', $data)) {
                $locked->account_id = $this->account($user, $data['account_id'], $locked->currency_code)?->id;
            }
            if (array_key_exists('category_id', $data)) {
                $locked->category_id = $this->category(
                    $user, $data['category_id'], $locked->direction === 'owe' ? 'expense' : 'income')?->id;
            }
            foreach (['name', 'deadline', 'note'] as $field) {
                if (array_key_exists($field, $data)) {
                    $locked->{$field} = $field === 'name'
                        ? trim((string) $data[$field]) : ($field === 'note' ? $this->nullableTrim($data[$field]) : $data[$field]);
                }
            }
            if (array_key_exists('active', $data)) {
                $locked->is_active = (bool) $data['active'];
            }
            if (array_key_exists('archived', $data)) {
                $locked->is_archived = (bool) $data['archived'];
                $locked->archived_at = $data['archived'] ? ($locked->archived_at ?? now()) : null;
            }
            $schedule = null;
            if (array_key_exists('schedule', $data)) {
                if ($locked->repayment_mode !== 'fixed' || $data['schedule'] === null) {
                    throw ValidationException::withMessages(['schedule' => __('messages.finance_debt_schedule_invalid')]);
                }
                $schedule = $this->schedules->validate($locked, $data['schedule']);
                $locked->fill($schedule);
            }
            $locked->save();
            if ($schedule) {
                $this->schedules->synchronize($locked, $schedule, $user->calendarTimezone());
            }

            return $locked->fresh();
        }, 3);
    }

    /** @return Collection<int, array<string, mixed>> */
    public function list(User $user, bool $archived = false): Collection
    {
        return FinanceDebt::query()->ownedBy($user)->where('is_archived', $archived)->orderBy('id')
            ->with(['counterparty', 'paymentFacts.transactionGroup.reversedBy',
                'recurringRule.occurrences.financeDebtDetail',
                'recurringRule.occurrences.financeDebtPaymentFact.transactionGroup.reversedBy'])
            ->get()->map(fn (FinanceDebt $debt): array => $this->projections->project($user, $debt));
    }

    private function account(User $user, mixed $id, string $currency): ?FinanceAccount
    {
        if ($id === null) {
            return null;
        }
        $account = FinanceAccount::query()->ownedBy($user)->findOrFail($id);
        if ($account->archived_at !== null || $account->currency_code !== $currency) {
            throw ValidationException::withMessages(['account_id' => __('messages.finance_debt_account_invalid')]);
        }

        return $account;
    }

    private function category(User $user, mixed $id, string $direction): ?FinanceCategory
    {
        if ($id === null) {
            return null;
        }
        $category = FinanceCategory::query()->ownedBy($user)->findOrFail($id);
        if ($category->archived_at !== null || $category->direction !== $direction) {
            throw ValidationException::withMessages(['category_id' => __('messages.finance_debt_category_invalid')]);
        }

        return $category;
    }

    private function purchase(User $user, mixed $id): ?Item
    {
        if ($id === null) {
            return null;
        }
        $item = Item::query()->ownedBy($user)->whereKey($id)->lockForUpdate()->firstOrFail();
        if ($item->type !== Item::TYPE_PURCHASE || $item->status !== Item::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['purchase_item_id' => __('messages.finance_purchase_invalid')]);
        }

        return $item;
    }

    private function nullableTrim(mixed $value): ?string
    {
        return $value === null ? null : trim((string) $value);
    }
}
