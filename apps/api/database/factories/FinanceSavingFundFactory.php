<?php

namespace Database\Factories;

use App\Models\FinanceAccount;
use App\Models\FinanceSavingFund;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinanceSavingFund> */
class FinanceSavingFundFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(), 'name' => fake()->words(2, true), 'fund_type' => 'regular',
            'storage_mode' => 'virtual',
            'account_id' => fn (array $attributes): int => FinanceAccount::factory()->create([
                'user_id' => $attributes['user_id'], 'currency_code' => 'UAH',
            ])->id,
            'currency_code' => 'UAH', 'target_mode' => 'explicit', 'target_amount' => '1000.0000',
            'top_up_mode' => 'none',
        ];
    }
}
