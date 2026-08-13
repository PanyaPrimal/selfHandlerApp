<?php

namespace App\Services\Finance;

use App\Models\FinanceFundMovement;
use App\Models\FinanceLedgerEntry;
use App\Models\FinanceRecurringOperation;
use App\Models\FinanceSavingFund;
use App\Models\User;
use App\Services\RecurringRuleExpander;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class FinanceFundProjectionService
{
    /** @var array<string,array<string,mixed>> */
    private array $expenseCache = [];

    /** @var array<string,array<string,mixed>> */
    private array $incomeCache = [];

    public function __construct(
        private readonly FinanceBalanceService $balances,
        private readonly FinanceExchangeRateService $exchangeRates,
        private readonly RecurringRuleExpander $expander,
    ) {}

    /** @return array<string, mixed> */
    public function project(User $user, FinanceSavingFund $fund, string $month): array
    {
        abort_unless($fund->isOwnedBy($user), 404);
        $fund->loadMissing(['movements.transactionGroup.reversedBy', 'account']);
        $balance = $this->balances->forAccount($fund->account);
        $reserved = $this->reservedForAccount($user, $fund->account_id);

        return $this->build($user, $fund, $month, $balance, $reserved);
    }

    /** @param Collection<int,FinanceSavingFund> $funds @return Collection<int,array<string,mixed>> */
    public function projectMany(User $user, Collection $funds, string $month): Collection
    {
        $funds->loadMissing(['movements.transactionGroup.reversedBy', 'account']);
        $accounts = $funds->pluck('account')->filter()->unique('id')->values();
        $balances = $this->balances->forAccounts($accounts);
        $reserved = FinanceFundMovement::query()->where('finance_fund_movements.user_id', $user->id)
            ->join('finance_saving_funds', 'finance_saving_funds.id', '=', 'finance_fund_movements.finance_saving_fund_id')
            ->where('finance_saving_funds.storage_mode', 'virtual')
            ->whereIn('finance_saving_funds.account_id', $accounts->pluck('id'))
            ->selectRaw('finance_saving_funds.account_id AS account_id, SUM(finance_fund_movements.delta_amount) AS reserved')
            ->groupBy('finance_saving_funds.account_id')->pluck('reserved', 'account_id');

        return $funds->mapWithKeys(fn (FinanceSavingFund $fund): array => [$fund->id => $this->build(
            $user, $fund, $month, $balances[$fund->account_id] ?? '0.0000',
            bcadd('0', (string) ($reserved[$fund->account_id] ?? '0'), 4),
        )]);
    }

    /** @return array<string,mixed> */
    private function build(User $user, FinanceSavingFund $fund, string $month, string $accountBalance, string $accountReserved): array
    {
        $saved = $fund->storage_mode === 'linked_account'
            ? $accountBalance
            : $fund->movements->reduce(fn (string $sum, FinanceFundMovement $movement): string => bcadd($sum, (string) $movement->delta_amount, 4), '0.0000');
        $calculation = $this->calculation($user, $fund, $month, $saved);
        $target = $calculation['target'];
        $remaining = $target === null ? null : bcsub($target, $saved, 4);
        if ($remaining !== null && bccomp($remaining, '0', 4) < 0) {
            $remaining = '0.0000';
        }
        $progress = $target === null ? null : (float) bcdiv($saved, $target, 8);
        $pace = null;
        if ($remaining !== null && $fund->deadline !== null && bccomp($remaining, '0', 4) > 0) {
            $monthStart = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
            $months = max(1, $monthStart->diffInMonths($fund->deadline->startOfMonth()) + 1);
            $pace = bcdiv($remaining, (string) $months, 4);
        }
        $overReserved = $fund->storage_mode === 'virtual'
            && bccomp($accountReserved, $accountBalance, 4) > 0;
        $state = ! $calculation['complete'] ? 'unavailable' : ($fund->spent_at !== null ? 'spent'
            : ($overReserved ? 'over_reserved' : ($target !== null && bccomp($saved, $target, 4) >= 0 ? 'reached'
                : ($fund->deadline && $fund->deadline->format('Y-m') < $month ? 'under_funded' : 'active'))));

        return [
            'month' => $month,
            'complete' => $calculation['complete'],
            'saved_amount' => $saved,
            'target_amount' => $target,
            'remaining_amount' => $remaining,
            'progress' => $progress,
            'suggested_top_up' => $calculation['suggested'],
            'required_monthly_pace' => $pace,
            'state' => $state,
            'missing_currencies' => $calculation['missing_currencies'],
            'missing_history' => $calculation['missing_history'],
            'calculation_basis' => $calculation['basis'],
            'conversions' => $calculation['conversions'],
        ];
    }

    /** @return array<string, mixed> */
    public function movement(FinanceFundMovement $movement): array
    {
        $movement->loadMissing(['transactionGroup', 'reversedBy']);

        return [
            'id' => $movement->id,
            'action' => $movement->action,
            'amount' => ltrim((string) $movement->delta_amount, '-'),
            'currency' => $movement->currency_code,
            'occurred_on' => $movement->occurred_on->format('Y-m-d'),
            'transaction_public_id' => $movement->transactionGroup?->public_id,
            'reversed' => $movement->reversedBy !== null,
        ];
    }

    /** @return array{target:?string,suggested:?string,complete:bool,missing_history:bool,missing_currencies:list<string>,basis:?string,conversions:list<array<string,mixed>>} */
    private function calculation(User $user, FinanceSavingFund $fund, string $month, string $saved): array
    {
        $target = $fund->target_amount === null ? null : (string) $fund->target_amount;
        $suggested = match ($fund->top_up_mode) {
            'none' => null,
            'fixed' => $fund->fixed_amount === null ? null : (string) $fund->fixed_amount,
            default => null,
        };
        $complete = $target !== null;
        $missingHistory = false;
        $missing = [];
        $conversions = [];
        $basis = null;
        if ($fund->target_mode === 'expense_months' || $fund->top_up_mode === 'expense_months') {
            $average = $this->expenseAverage($user, $fund->currency_code, $month);
            $missingHistory = $average['missing_history'];
            $missing = $average['missing_currencies'];
            $conversions = $average['conversions'];
            $basis = 'three_complete_prior_months';
            if ($average['amount'] !== null) {
                if ($fund->target_mode === 'expense_months') {
                    $target = bcmul($average['amount'], (string) $fund->expense_months, 4);
                }
                if ($fund->top_up_mode === 'expense_months' && $target !== null) {
                    $shortfall = bcsub($target, $saved, 4);
                    if (bccomp($shortfall, '0', 4) < 0) {
                        $shortfall = '0.0000';
                    }
                    $suggested = bcdiv($shortfall, (string) max(1, $fund->build_months), 4);
                }
            }
            $complete = $target !== null && $average['amount'] !== null;
        }
        if ($fund->top_up_mode === 'income_percent') {
            $income = $this->plannedIncome($user, $fund->currency_code, $month);
            $missing = array_values(array_unique([...$missing, ...$income['missing_currencies']]));
            $conversions = [...$conversions, ...$income['conversions']];
            $basis = 'planned_income_percent';
            $suggested = $income['amount'] === null ? null
                : $this->roundHalfUp(bcmul($income['amount'], (string) $fund->income_percent, 12), 100);
            $complete = $target !== null && $suggested !== null;
        }
        sort($missing);

        return ['target' => $target, 'suggested' => $suggested, 'complete' => $complete,
            'missing_history' => $missingHistory, 'missing_currencies' => $missing,
            'basis' => $basis, 'conversions' => $conversions];
    }

    /** @return array{amount:?string,missing_history:bool,missing_currencies:list<string>,conversions:list<array<string,mixed>>} */
    private function expenseAverage(User $user, string $currency, string $month): array
    {
        $cacheKey = $user->id.':'.$currency.':'.$month;
        if (isset($this->expenseCache[$cacheKey])) {
            return $this->expenseCache[$cacheKey];
        }
        $current = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
        $from = $current->subMonths(3);
        $to = $current->subDay();
        $catalog = $this->exchangeRates->catalog($user, $to->toDateString());
        $entries = FinanceLedgerEntry::query()->ownedBy($user)->where('delta_amount', '<', 0)
            ->whereHas('transactionGroup', fn ($query) => $query->where('kind', 'expense')
                ->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()])
                ->whereNull('reverses_group_id')->whereDoesntHave('reversedBy'))
            ->with('transactionGroup')->get();
        $months = [];
        $total = '0.0000';
        $missing = [];
        $conversions = [];
        foreach ($entries as $entry) {
            $date = $entry->transactionGroup->occurred_on->format('Y-m-d');
            $months[substr($date, 0, 7)] = true;
            $amount = ltrim((string) $entry->delta_amount, '-');
            $lookup = $this->exchangeRates->lookup($user, $entry->currency_code, $currency, $date, $catalog);
            if (! $lookup) {
                $missing[] = $entry->currency_code;

                continue;
            }
            $converted = $this->exchangeRates->convert($amount, $entry->currency_code, $currency, $lookup);
            $total = bcadd($total, $converted, 4);
            $conversions[] = ['on' => $date, 'from_currency' => $entry->currency_code,
                'source_amount' => $amount, 'converted_amount' => $converted, 'rate' => $lookup['rate'],
                'rate_date' => $lookup['date'], 'rate_direction' => $lookup['direction']];
        }
        $historyMissing = count($months) < 3;

        return $this->expenseCache[$cacheKey] = ['amount' => $historyMissing || $missing !== [] ? null : bcdiv($total, '3', 4),
            'missing_history' => $historyMissing, 'missing_currencies' => array_values(array_unique($missing)),
            'conversions' => $conversions];
    }

    /** @return array{amount:?string,missing_currencies:list<string>,conversions:list<array<string,mixed>>} */
    private function plannedIncome(User $user, string $currency, string $month): array
    {
        $cacheKey = $user->id.':'.$currency.':'.$month;
        if (isset($this->incomeCache[$cacheKey])) {
            return $this->incomeCache[$cacheKey];
        }
        $from = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
        $to = $from->endOfMonth();
        $operations = FinanceRecurringOperation::query()->ownedBy($user)->where('direction', 'income')
            ->where('is_active', true)->where('is_archived', false)->with('recurringRule.ruleMonthdays')->get();
        $catalog = $this->exchangeRates->catalog($user, $to->toDateString());
        $total = '0.0000';
        $missing = [];
        $conversions = [];
        foreach ($operations as $operation) {
            if (! $operation->recurringRule) {
                continue;
            }
            foreach ($this->expander->datesBetween(
                $operation->recurringRule, $from->toDateString(), $to->toDateString()) as $date) {
                $lookup = $this->exchangeRates->lookup($user, $operation->currency_code, $currency, $date, $catalog);
                if (! $lookup) {
                    $missing[] = $operation->currency_code;

                    continue;
                }
                $converted = $this->exchangeRates->convert((string) $operation->amount, $operation->currency_code, $currency, $lookup);
                $total = bcadd($total, $converted, 4);
                $conversions[] = ['on' => $date, 'from_currency' => $operation->currency_code,
                    'source_amount' => (string) $operation->amount, 'converted_amount' => $converted,
                    'rate' => $lookup['rate'], 'rate_date' => $lookup['date'], 'rate_direction' => $lookup['direction']];
            }
        }

        return $this->incomeCache[$cacheKey] = ['amount' => $missing === [] ? $total : null, 'missing_currencies' => array_values(array_unique($missing)),
            'conversions' => $conversions];
    }

    private function reservedForAccount(User $user, int $accountId): string
    {
        $value = FinanceFundMovement::query()->where('finance_fund_movements.user_id', $user->id)
            ->join('finance_saving_funds', 'finance_saving_funds.id', '=', 'finance_fund_movements.finance_saving_fund_id')
            ->where('finance_saving_funds.storage_mode', 'virtual')->where('finance_saving_funds.account_id', $accountId)
            ->sum('finance_fund_movements.delta_amount');

        return bcadd('0', (string) ($value ?: '0'), 4);
    }

    private function roundHalfUp(string $product, int $divisor): string
    {
        $value = bcdiv($product, (string) $divisor, 8);

        return bcadd(bcadd($value, '0.00005', 8), '0', 4);
    }
}
