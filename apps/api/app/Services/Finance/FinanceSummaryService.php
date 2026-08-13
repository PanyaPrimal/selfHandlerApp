<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceExchangeRate;
use App\Models\FinanceLedgerEntry;
use App\Models\User;
use Illuminate\Support\Collection;

class FinanceSummaryService
{
    public function __construct(private readonly FinanceExchangeRateService $exchangeRates) {}

    /** @return array{accounts:Collection<int,FinanceAccount>,consolidated:array<string,mixed>,actuals:array<string,mixed>} */
    public function build(User $user, string $from, string $to, string $asOf): array
    {
        $base = $user->ensureProfile()->base_currency;
        $accounts = FinanceAccount::query()->ownedBy($user)->orderBy('name')->orderBy('id')->get();
        $balances = $accounts->mapWithKeys(fn (FinanceAccount $account): array => [$account->id => '0.0000'])->all();
        if ($accounts->isNotEmpty()) {
            FinanceLedgerEntry::query()
                ->join('finance_transaction_groups as finance_groups', 'finance_groups.id', '=', 'finance_ledger_entries.transaction_group_id')
                ->where('finance_ledger_entries.user_id', $user->id)
                ->whereIn('finance_ledger_entries.account_id', $accounts->pluck('id'))
                ->whereDate('finance_groups.occurred_on', '<=', $asOf)
                ->selectRaw('finance_ledger_entries.account_id, SUM(finance_ledger_entries.delta_amount) AS balance')
                ->groupBy('finance_ledger_entries.account_id')
                ->get()
                ->each(function (FinanceLedgerEntry $entry) use (&$balances): void {
                    $balances[$entry->account_id] = bcadd('0', (string) $entry->getAttribute('balance'), 4);
                });
        }
        $accounts->each(fn (FinanceAccount $account) => $account->setAttribute(
            'balance_projection',
            $balances[$account->id],
        ));

        $through = max($to, $asOf);
        $catalog = $this->exchangeRates->catalog($user, $through);
        $currencyBalances = [];
        foreach ($accounts as $account) {
            $currencyBalances[$account->currency_code] = bcadd(
                $currencyBalances[$account->currency_code] ?? '0.0000',
                $balances[$account->id],
                4,
            );
        }

        $consolidated = $this->consolidate($user, $currencyBalances, $base, $asOf, $catalog);
        $actuals = $this->actuals($user, $from, $to, $base, $catalog);

        return ['accounts' => $accounts, 'consolidated' => $consolidated, 'actuals' => $actuals];
    }

    /**
     * @param  array<string,string>  $amounts
     * @param  Collection<int, FinanceExchangeRate>  $catalog
     * @return array<string,mixed>
     */
    private function consolidate(User $user, array $amounts, string $base, string $asOf, Collection $catalog): array
    {
        ksort($amounts);
        $total = '0.0000';
        $missing = [];
        $conversions = [];
        foreach ($amounts as $currency => $amount) {
            if (bccomp($amount, '0', 4) === 0) {
                continue;
            }
            $lookup = $this->exchangeRates->lookup($user, $currency, $base, $asOf, $catalog);
            if (! $lookup) {
                $missing[] = $currency;

                continue;
            }
            $converted = $this->exchangeRates->convert($amount, $currency, $base, $lookup);
            $total = bcadd($total, $converted, 4);
            $conversions[] = [
                'currency' => $currency,
                'amount' => $amount,
                'converted_amount' => $converted,
                'rate' => $lookup['rate'],
                'rate_date' => $lookup['date'],
                'rate_direction' => $lookup['direction'],
            ];
        }
        sort($missing);

        return [
            'as_of' => $asOf,
            'base_currency' => $base,
            'complete' => $missing === [],
            'total' => $missing === [] ? $total : null,
            'missing_currencies' => $missing,
            'conversions' => $conversions,
        ];
    }

    /**
     * @param  Collection<int, FinanceExchangeRate>  $catalog
     * @return array<string,mixed>
     */
    private function actuals(User $user, string $from, string $to, string $base, Collection $catalog): array
    {
        $entries = FinanceLedgerEntry::query()
            ->join('finance_transaction_groups as finance_groups', 'finance_groups.id', '=', 'finance_ledger_entries.transaction_group_id')
            ->where('finance_ledger_entries.user_id', $user->id)
            ->whereIn('finance_groups.kind', ['income', 'expense'])
            ->whereBetween('finance_groups.occurred_on', [$from, $to])
            ->selectRaw('finance_groups.kind, finance_groups.occurred_on, finance_ledger_entries.currency_code, SUM(finance_ledger_entries.delta_amount) AS total_delta')
            ->groupBy('finance_groups.kind', 'finance_groups.occurred_on', 'finance_ledger_entries.currency_code')
            ->orderBy('finance_groups.occurred_on')
            ->get();
        $income = '0.0000';
        $expense = '0.0000';
        $missing = [];
        foreach ($entries as $entry) {
            $amount = bcadd('0', (string) $entry->getAttribute('total_delta'), 4);
            if (bccomp($amount, '0', 4) === 0) {
                continue;
            }

            $date = (string) $entry->getAttribute('occurred_on');
            $lookup = $this->exchangeRates->lookup($user, $entry->currency_code, $base, $date, $catalog);
            if (! $lookup) {
                $missing[] = $entry->currency_code;

                continue;
            }
            $converted = $this->exchangeRates->convert(
                $amount,
                $entry->currency_code,
                $base,
                $lookup,
            );
            if ($entry->getAttribute('kind') === 'income') {
                $income = bcadd($income, $converted, 4);
            } else {
                $expense = bcsub($expense, $converted, 4);
            }
        }
        $missing = array_values(array_unique($missing));
        sort($missing);
        $complete = $missing === [];

        return [
            'from' => $from,
            'to' => $to,
            'base_currency' => $base,
            'complete' => $complete,
            'income' => $complete ? $income : null,
            'expense' => $complete ? $expense : null,
            'net' => $complete ? bcsub($income, $expense, 4) : null,
            'missing_currencies' => $missing,
        ];
    }
}
