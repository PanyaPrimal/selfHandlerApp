<?php

namespace Database\Factories;

use App\Models\FinanceCounterparty;
use App\Models\FinanceDebt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinanceDebt> */
class FinanceDebtFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'finance_counterparty_id' => fn (array $attributes): int => FinanceCounterparty::factory()
                ->create(['user_id' => $attributes['user_id']])->id,
            'name' => fake()->words(2, true), 'direction' => 'owe', 'repayment_mode' => 'flexible',
            'original_amount' => '1000.0000', 'currency_code' => 'UAH', 'originated_on' => '2026-08-01',
        ];
    }
}
