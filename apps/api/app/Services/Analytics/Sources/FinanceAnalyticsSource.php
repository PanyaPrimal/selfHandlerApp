<?php

namespace App\Services\Analytics\Sources;

use App\Contracts\AnalyticsMetricSource;
use App\Models\User;
use App\Services\Finance\FinanceAnalyticsSeriesService;

class FinanceAnalyticsSource implements AnalyticsMetricSource
{
    public function __construct(private readonly FinanceAnalyticsSeriesService $series) {}

    public function keys(): array
    {
        return ['finance.income', 'finance.expense', 'finance.net'];
    }

    public function daily(User $user, string $from, string $to, array $keys): array
    {
        $rows = $this->series->daily($user, $from, $to);
        $mapping = ['finance.income' => 'income', 'finance.expense' => 'expense', 'finance.net' => 'net'];
        $result = [];
        foreach ($mapping as $key => $sourceKey) {
            if (in_array($key, $keys, true)) {
                $result[$key] = $rows[$sourceKey];
            }
        }

        return $result;
    }
}
