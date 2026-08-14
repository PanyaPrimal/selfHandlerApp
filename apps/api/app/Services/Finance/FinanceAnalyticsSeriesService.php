<?php

namespace App\Services\Finance;

use App\Models\FinanceLedgerEntry;
use App\Models\User;

class FinanceAnalyticsSeriesService
{
    public function __construct(private readonly FinanceExchangeRateService $exchangeRates) {}

    /** @return array{income:list<array<string,mixed>>,expense:list<array<string,mixed>>,net:list<array<string,mixed>>} */
    public function daily(User $user, string $from, string $to): array
    {
        $base = $user->ensureProfile()->base_currency;
        $catalog = $this->exchangeRates->catalog($user, $to);
        $rows = FinanceLedgerEntry::query()
            ->join('finance_transaction_groups as finance_groups', 'finance_groups.id', '=', 'finance_ledger_entries.transaction_group_id')
            ->where('finance_ledger_entries.user_id', $user->id)
            ->whereIn('finance_groups.kind', ['income', 'expense'])
            ->whereBetween('finance_groups.occurred_on', [$from, $to])
            ->selectRaw('finance_groups.kind, finance_groups.occurred_on, finance_ledger_entries.currency_code,
                SUM(finance_ledger_entries.delta_amount) AS total_delta, COUNT(finance_ledger_entries.id) AS samples')
            ->groupBy('finance_groups.kind', 'finance_groups.occurred_on', 'finance_ledger_entries.currency_code')
            ->orderBy('finance_groups.occurred_on')->get();
        $days = [];
        foreach ($rows as $row) {
            $amount = bcadd('0', (string) $row->getAttribute('total_delta'), 4);
            if (bccomp($amount, '0', 4) === 0) {
                continue;
            }
            $date = (string) $row->getAttribute('occurred_on');
            $days[$date] ??= ['income' => '0.0000', 'expense' => '0.0000', 'samples' => 0, 'missing' => []];
            $days[$date]['samples'] += (int) $row->getAttribute('samples');
            $lookup = $this->exchangeRates->lookup($user, $row->currency_code, $base, $date, $catalog);
            if (! $lookup) {
                $days[$date]['missing'][] = $row->currency_code;

                continue;
            }
            $converted = $this->exchangeRates->convert($amount, $row->currency_code, $base, $lookup);
            if ($row->getAttribute('kind') === 'income') {
                $days[$date]['income'] = bcadd($days[$date]['income'], $converted, 4);
            } else {
                $days[$date]['expense'] = bcsub($days[$date]['expense'], $converted, 4);
            }
        }

        $income = $expense = $net = [];
        foreach ($days as $date => $day) {
            $missing = array_values(array_unique($day['missing']));
            sort($missing);
            $complete = $missing === [];
            $reasons = array_map(fn (string $currency): string => 'missing_fx:'.$currency, $missing);
            $income[] = $this->primitive($date, $complete ? $day['income'] : null, $day['samples'], $complete, $reasons);
            $expense[] = $this->primitive($date, $complete ? $day['expense'] : null, $day['samples'], $complete, $reasons);
            $net[] = $this->primitive(
                $date,
                $complete ? bcsub($day['income'], $day['expense'], 4) : null,
                $day['samples'],
                $complete,
                $reasons,
            );
        }

        return compact('income', 'expense', 'net');
    }

    /** @return array<string,mixed> */
    private function primitive(string $date, ?string $value, int $samples, bool $complete, array $reasons): array
    {
        return [
            'date' => $date, 'numerator' => $value, 'denominator' => null,
            'sample_count' => $samples, 'complete' => $complete, 'reasons' => $reasons,
        ];
    }
}
