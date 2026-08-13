<?php

namespace Database\Factories;

use App\Models\FinanceBudgetLimit;
use App\Models\FinanceCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinanceBudgetLimit> */
class FinanceBudgetLimitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category_id' => fn (array $attributes): int => FinanceCategory::factory()->create([
                'user_id' => $attributes['user_id'], 'direction' => 'expense',
            ])->id,
            'budget_month' => now()->startOfMonth()->toDateString(),
            'limit_amount' => '1000.0000',
            'currency_code' => 'UAH',
        ];
    }
}
