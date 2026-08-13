<?php

namespace Database\Factories;

use App\Models\FinanceAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinanceAccount> */
class FinanceAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'type' => fake()->randomElement(FinanceAccount::TYPES),
            'currency_code' => 'UAH',
            'archived_at' => null,
        ];
    }
}
