<?php

namespace App\Services\Finance;

use App\Models\FinanceExchangeRate;
use App\Models\User;
use App\ValueObjects\Money;
use Illuminate\Support\Collection;

class FinanceExchangeRateService
{
    /** @param array<string, mixed> $data @return array{FinanceExchangeRate,bool} */
    public function upsert(User $user, array $data): array
    {
        $rate = FinanceExchangeRate::query()->ownedBy($user)->firstOrNew([
            'from_currency' => $data['from_currency'],
            'to_currency' => $data['to_currency'],
            'rate_date' => $data['rate_date'],
        ]);
        $created = ! $rate->exists;
        $rate->fill([
            'user_id' => $user->id,
            'from_currency' => $data['from_currency'],
            'to_currency' => $data['to_currency'],
            'rate_date' => $data['rate_date'],
            'rate' => $this->canonicalRate((string) $data['rate']),
            'source' => 'manual',
        ])->save();

        return [$rate->fresh(), $created];
    }

    /** @return Collection<int, FinanceExchangeRate> */
    public function catalog(User $user, string $through): Collection
    {
        return FinanceExchangeRate::query()->ownedBy($user)
            ->whereDate('rate_date', '<=', $through)->orderByDesc('rate_date')->orderByDesc('id')->get();
    }

    /**
     * @param  Collection<int, FinanceExchangeRate>|null  $catalog
     * @return array{rate:string,date:string,direction:string}|null
     */
    public function lookup(
        User $user,
        string $from,
        string $to,
        string $date,
        ?Collection $catalog = null,
    ): ?array {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            return ['rate' => '1.000000000000', 'date' => $date, 'direction' => 'identity'];
        }

        $rates = ($catalog ?? $this->catalog($user, $date))->filter(
            fn (FinanceExchangeRate $rate): bool => $rate->rate_date->format('Y-m-d') <= $date
                && (($rate->from_currency === $from && $rate->to_currency === $to)
                    || ($rate->from_currency === $to && $rate->to_currency === $from)),
        )->sort(function (FinanceExchangeRate $left, FinanceExchangeRate $right) use ($from, $to): int {
            $dateOrder = strcmp($right->rate_date->format('Y-m-d'), $left->rate_date->format('Y-m-d'));
            if ($dateOrder !== 0) {
                return $dateOrder;
            }
            $leftDirect = $left->from_currency === $from && $left->to_currency === $to;
            $rightDirect = $right->from_currency === $from && $right->to_currency === $to;

            return $leftDirect === $rightDirect ? $right->id <=> $left->id : ($leftDirect ? -1 : 1);
        });
        $selected = $rates->first();
        if (! $selected) {
            return null;
        }
        $direct = $selected->from_currency === $from && $selected->to_currency === $to;

        return [
            'rate' => $direct ? (string) $selected->rate : $this->divideRound('1', (string) $selected->rate, 12),
            'date' => $selected->rate_date->format('Y-m-d'),
            'direction' => $direct ? 'direct' : 'inverse',
        ];
    }

    /** @param array{rate:string,date:string,direction:string} $lookup */
    public function convert(string $amount, string $from, string $to, array $lookup): string
    {
        return Money::of($amount, $from)->convert($to, $lookup['rate'])->amount();
    }

    public function canonicalRate(string $rate): string
    {
        return bcadd($rate, '0', 12);
    }

    private function divideRound(string $numerator, string $denominator, int $scale): string
    {
        $value = bcdiv($numerator, $denominator, $scale + 4);
        $increment = '0.'.str_repeat('0', $scale).'5';

        return bcadd(bcadd($value, $increment, $scale + 4), '0', $scale);
    }
}
