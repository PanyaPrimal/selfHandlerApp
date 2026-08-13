<?php

namespace Database\Factories;

use App\Models\FinanceExchangeRate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinanceExchangeRate> */
class FinanceExchangeRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'from_currency' => 'USD',
            'to_currency' => 'UAH',
            'rate_date' => now()->toDateString(),
            'rate' => '41.250000000000',
            'source' => 'manual',
        ];
    }
}
