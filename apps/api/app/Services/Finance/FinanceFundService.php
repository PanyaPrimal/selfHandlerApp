<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceSavingFund;
use App\Models\RecurringRule;
use App\Models\User;
use App\Services\RecurrenceMaterializer;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceFundService
{
    public function __construct(
        private readonly FinanceFundProjectionService $projections,
        private readonly RecurrenceMaterializer $materializer,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): FinanceSavingFund
    {
        return DB::transaction(function () use ($user, $data): FinanceSavingFund {
            $currency = strtoupper((string) $data['currency']);
            $account = $this->account($user, $data['account_id'], $currency);
            $funding = isset($data['funding_account_id'])
                ? $this->account($user, $data['funding_account_id'], $currency) : null;
            $category = isset($data['category_id']) ? FinanceCategory::query()->ownedBy($user)->findOrFail($data['category_id']) : null;
            if ($category && ($category->archived_at !== null || $category->direction !== 'expense')) {
                throw ValidationException::withMessages(['category_id' => __('messages.finance_fund_category_invalid')]);
            }
            $type = (string) $data['fund_type'];
            $storage = (string) $data['storage_mode'];
            $targetMode = (string) $data['target_mode'];
            $rule = $data['rule'];
            if (! in_array($type, FinanceSavingFund::TYPES, true)
                || ! in_array($storage, FinanceSavingFund::STORAGE_MODES, true)
                || ! in_array($targetMode, FinanceSavingFund::TARGET_MODES, true)
                || ! in_array($rule['top_up_mode'], FinanceSavingFund::TOP_UP_MODES, true)) {
                throw ValidationException::withMessages(['fund_type' => __('messages.finance_fund_invalid')]);
            }
            if (($storage === 'virtual' && $funding !== null)
                || ($storage === 'linked_account' && $funding?->id === $account->id)) {
                throw ValidationException::withMessages(['funding_account_id' => __('messages.finance_fund_accounts_invalid')]);
            }
            if ($storage === 'linked_account' && FinanceSavingFund::query()->ownedBy($user)
                ->where('linked_account_key', $account->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['account_id' => __('messages.finance_fund_linked_account_claimed')]);
            }
            if ($type === 'regular' && ($targetMode !== 'explicit' || ! in_array($rule['top_up_mode'], ['none', 'fixed'], true))) {
                throw ValidationException::withMessages(['rule' => __('messages.finance_fund_rule_invalid')]);
            }
            if ($type === 'emergency' && $rule['top_up_mode'] === 'none') {
                throw ValidationException::withMessages(['rule.top_up_mode' => __('messages.finance_fund_rule_required')]);
            }
            $target = $data['target_amount'] === null ? null : $this->positive((string) $data['target_amount'], $currency, 'target_amount');
            if (($targetMode === 'explicit') !== ($target !== null)) {
                throw ValidationException::withMessages(['target_amount' => __('messages.finance_fund_target_invalid')]);
            }
            $this->validateRule($type, $rule, $currency);

            $fund = FinanceSavingFund::query()->create([
                'user_id' => $user->id,
                'name' => trim((string) $data['name']),
                'fund_type' => $type,
                'storage_mode' => $storage,
                'account_id' => $account->id,
                'linked_account_key' => $storage === 'linked_account' ? $account->id : null,
                'funding_account_id' => $funding?->id,
                'category_id' => $category?->id,
                'currency_code' => $currency,
                'target_mode' => $targetMode,
                'target_amount' => $target,
                'deadline' => $data['deadline'] ?? null,
                'top_up_mode' => $rule['top_up_mode'],
                'fixed_amount' => $rule['fixed_amount'] === null ? null : $this->positive((string) $rule['fixed_amount'], $currency, 'rule.fixed_amount'),
                'income_percent' => $rule['income_percent'],
                'expense_months' => $rule['expense_months'],
                'build_months' => $rule['build_months'],
                'starts_on' => $rule['starts_on'],
                'monthday' => $rule['monthday'],
                'reminder_time' => $rule['reminder_time'],
                'note' => $data['note'] === null ? null : trim((string) $data['note']),
            ]);
            $this->syncRule($user, $fund);

            return $fund;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, FinanceSavingFund $fund, array $data): FinanceSavingFund
    {
        abort_unless($fund->isOwnedBy($user), 404);

        return DB::transaction(function () use ($user, $fund, $data): FinanceSavingFund {
            $locked = FinanceSavingFund::query()->ownedBy($user)->whereKey($fund->id)->lockForUpdate()->firstOrFail();
            if (array_key_exists('funding_account_id', $data)) {
                $locked->funding_account_id = $data['funding_account_id'] === null ? null
                    : $this->account($user, $data['funding_account_id'], $locked->currency_code)->id;
                if ($locked->funding_account_id === $locked->account_id) {
                    throw ValidationException::withMessages([
                        'funding_account_id' => __('messages.finance_fund_accounts_invalid')]);
                }
            }
            if (array_key_exists('category_id', $data)) {
                $category = $data['category_id'] === null ? null : FinanceCategory::query()->ownedBy($user)->findOrFail($data['category_id']);
                if ($category && ($category->archived_at !== null || $category->direction !== 'expense')) {
                    throw ValidationException::withMessages(['category_id' => __('messages.finance_fund_category_invalid')]);
                }
                $locked->category_id = $category?->id;
            }
            foreach (['name', 'deadline', 'note'] as $field) {
                if (array_key_exists($field, $data)) {
                    $locked->{$field} = $field === 'name' ? trim((string) $data[$field])
                        : ($field === 'note' ? ($data[$field] === null ? null : trim((string) $data[$field])) : $data[$field]);
                }
            }
            if (array_key_exists('target_amount', $data)) {
                $locked->target_amount = $data['target_amount'] === null ? null
                    : $this->positive((string) $data['target_amount'], $locked->currency_code, 'target_amount');
            }
            if (array_key_exists('rule', $data)) {
                $this->validateRule($locked->fund_type, $data['rule'], $locked->currency_code);
                $rule = $data['rule'];
                foreach (['top_up_mode', 'fixed_amount', 'income_percent', 'expense_months', 'build_months',
                    'starts_on', 'monthday', 'reminder_time'] as $field) {
                    $locked->{$field} = $field === 'fixed_amount' && $rule[$field] !== null
                        ? $this->positive((string) $rule[$field], $locked->currency_code, 'rule.fixed_amount') : $rule[$field];
                }
            }
            if (array_key_exists('active', $data)) {
                $locked->is_active = (bool) $data['active'];
            }
            if (array_key_exists('archived', $data)) {
                $locked->is_archived = (bool) $data['archived'];
                $locked->archived_at = $data['archived'] ? ($locked->archived_at ?? now()) : null;
            }
            if (array_key_exists('spent', $data)) {
                $locked->spent_at = $data['spent'] ? ($locked->spent_at ?? now()) : null;
            }
            $locked->save();
            $this->syncRule($user, $locked);

            return $locked->fresh();
        }, 3);
    }

    /** @return array<string, mixed> */
    public function one(User $user, FinanceSavingFund $fund, ?string $month = null): array
    {
        abort_unless($fund->isOwnedBy($user), 404);
        $month ??= CarbonImmutable::now($user->calendarTimezone())->format('Y-m');
        $fund->unsetRelations();
        $fund->load(['movements.transactionGroup.reversedBy', 'movements.reversedBy', 'account']);

        return $this->serialize($fund, $this->projections->project($user, $fund, $month));
    }

    /** @param array<string,mixed> $projection @return array<string,mixed> */
    private function serialize(FinanceSavingFund $fund, array $projection): array
    {

        return [
            'id' => $fund->id,
            'name' => $fund->name,
            'fund_type' => $fund->fund_type,
            'storage_mode' => $fund->storage_mode,
            'account_id' => $fund->account_id,
            'funding_account_id' => $fund->funding_account_id,
            'category_id' => $fund->category_id,
            'currency' => $fund->currency_code,
            'target_mode' => $fund->target_mode,
            'deadline' => $fund->deadline?->format('Y-m-d'),
            'rule' => [
                'top_up_mode' => $fund->top_up_mode,
                'fixed_amount' => $fund->fixed_amount === null ? null : (string) $fund->fixed_amount,
                'income_percent' => $fund->income_percent === null ? null : (float) $fund->income_percent,
                'expense_months' => $fund->expense_months,
                'build_months' => $fund->build_months,
                'starts_on' => $fund->starts_on?->format('Y-m-d'),
                'monthday' => $fund->monthday,
                'reminder_time' => $fund->reminder_time,
            ],
            'active' => $fund->is_active,
            'archived' => $fund->is_archived,
            'spent' => $fund->spent_at !== null,
            'projection' => $projection,
            'movements' => $fund->movements->sortByDesc('id')->take(500)->values()
                ->map(fn ($movement): array => $this->projections->movement($movement)),
            'created_at' => $fund->created_at?->toISOString(),
            'updated_at' => $fund->updated_at?->toISOString(),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function list(User $user, ?string $month = null, bool $archived = false): Collection
    {
        $month ??= CarbonImmutable::now($user->calendarTimezone())->format('Y-m');
        $funds = FinanceSavingFund::query()->ownedBy($user)->where('is_archived', $archived)->orderBy('id')
            ->with(['movements.transactionGroup.reversedBy', 'movements.reversedBy', 'account'])->get();
        $projections = $this->projections->projectMany($user, $funds, $month);

        return $funds->map(fn (FinanceSavingFund $fund): array => $this->serialize($fund, $projections[$fund->id]));
    }

    private function account(User $user, mixed $id, string $currency): FinanceAccount
    {
        $account = FinanceAccount::query()->ownedBy($user)->findOrFail($id);
        if ($account->archived_at !== null || $account->currency_code !== $currency) {
            throw ValidationException::withMessages(['account_id' => __('messages.finance_fund_account_invalid')]);
        }

        return $account;
    }

    /** @param array<string, mixed> $rule */
    private function validateRule(string $type, array $rule, string $currency): void
    {
        $mode = $rule['top_up_mode'];
        if ($mode === 'fixed') {
            $this->positive((string) $rule['fixed_amount'], $currency, 'rule.fixed_amount');
        }
        if ($mode === 'income_percent' && ((float) $rule['income_percent'] <= 0 || (float) $rule['income_percent'] > 100)) {
            throw ValidationException::withMessages(['rule.income_percent' => __('messages.finance_fund_rule_invalid')]);
        }
        if ($mode === 'expense_months' && ((int) $rule['expense_months'] < 1 || (int) $rule['expense_months'] > 24
            || (int) $rule['build_months'] < 1 || (int) $rule['build_months'] > 60)) {
            throw ValidationException::withMessages(['rule.expense_months' => __('messages.finance_fund_rule_invalid')]);
        }
        if ($mode !== 'none' && ((int) $rule['monthday'] < 1 || (int) $rule['monthday'] > 31 || empty($rule['starts_on']))) {
            throw ValidationException::withMessages(['rule.monthday' => __('messages.finance_fund_rule_invalid')]);
        }
    }

    private function positive(string $amount, string $currency, string $field): string
    {
        $money = Money::of($amount, $currency);
        if (bccomp($money->amount(), '0', 4) <= 0) {
            throw ValidationException::withMessages([$field => __('messages.finance_positive_money')]);
        }

        return $money->amount();
    }

    private function syncRule(User $user, FinanceSavingFund $fund): void
    {
        $rule = $fund->recurringRule()->first();
        if ($fund->top_up_mode === 'none') {
            if ($rule) {
                $this->materializer->materialize($rule, null, false);
            }

            return;
        }
        $rule ??= new RecurringRule([
            'user_id' => $user->id, 'owner_type' => RecurringRule::OWNER_FINANCE_SAVING_FUND,
            'owner_id' => $fund->id,
        ]);
        $rule->fill([
            'frequency' => RecurringRule::FREQUENCY_MONTHLY, 'interval_count' => 1,
            'starts_on' => $fund->starts_on, 'ends_on' => null, 'timezone' => $user->calendarTimezone(),
            'slot_time' => $fund->reminder_time,
        ])->save();
        $rule->syncMonthdays([$fund->monthday]);
        $this->materializer->materialize($rule, $fund->starts_on?->format('Y-m-d'),
            $fund->is_active && ! $fund->is_archived && $fund->spent_at === null);
    }
}
