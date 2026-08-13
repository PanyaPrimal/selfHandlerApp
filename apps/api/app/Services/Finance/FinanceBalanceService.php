<?php

namespace App\Services\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceLedgerEntry;
use Illuminate\Support\Collection;

class FinanceBalanceService
{
    /**
     * @param  Collection<int, FinanceAccount>  $accounts
     * @return array<int, string>
     */
    public function forAccounts(Collection $accounts): array
    {
        $balances = $accounts->mapWithKeys(fn (FinanceAccount $account): array => [
            $account->id => '0.0000',
        ])->all();

        if ($accounts->isEmpty()) {
            return $balances;
        }

        FinanceLedgerEntry::query()
            ->whereIn('account_id', $accounts->pluck('id'))
            ->selectRaw('account_id, SUM(delta_amount) AS balance')
            ->groupBy('account_id')
            ->get()
            ->each(function (FinanceLedgerEntry $entry) use (&$balances): void {
                $balances[$entry->account_id] = bcadd('0', (string) $entry->getAttribute('balance'), 4);
            });

        return $balances;
    }

    public function forAccount(FinanceAccount $account, bool $lock = false): string
    {
        $query = FinanceLedgerEntry::query()->where('account_id', $account->id);
        if (! $lock) {
            return bcadd('0', (string) ($query->sum('delta_amount') ?: '0'), 4);
        }

        return $query->orderBy('id')->lockForUpdate()->pluck('delta_amount')->reduce(
            fn (string $sum, mixed $delta): string => bcadd($sum, (string) $delta, 4),
            '0.0000',
        );
    }
}
