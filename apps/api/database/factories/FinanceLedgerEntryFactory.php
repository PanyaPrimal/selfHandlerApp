<?php

namespace Database\Factories;

use App\Models\FinanceAccount;
use App\Models\FinanceLedgerEntry;
use App\Models\FinanceTransactionGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinanceLedgerEntry> */
class FinanceLedgerEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'transaction_group_id' => fn (array $attributes): int => FinanceTransactionGroup::factory()
                ->create(['user_id' => $attributes['user_id']])->id,
            'account_id' => fn (array $attributes): int => FinanceAccount::factory()
                ->create(['user_id' => $attributes['user_id'], 'currency_code' => $attributes['currency_code'] ?? 'UAH'])->id,
            'category_id' => null,
            'role' => 'primary',
            'delta_amount' => '10.0000',
            'currency_code' => 'UAH',
        ];
    }
}
