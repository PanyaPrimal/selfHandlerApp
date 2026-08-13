<?php

namespace Database\Factories;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceRecurringOperation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinanceRecurringOperation> */
class FinanceRecurringOperationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'direction' => 'expense',
            'account_id' => fn (array $attributes): int => FinanceAccount::factory()->create([
                'user_id' => $attributes['user_id'], 'currency_code' => $attributes['currency_code'] ?? 'UAH',
            ])->id,
            'category_id' => fn (array $attributes): int => FinanceCategory::factory()->create([
                'user_id' => $attributes['user_id'], 'direction' => $attributes['direction'] ?? 'expense',
            ])->id,
            'amount' => '100.0000',
            'currency_code' => 'UAH',
            'is_mandatory' => true,
            'is_active' => true,
            'is_archived' => false,
            'archived_at' => null,
        ];
    }
}
