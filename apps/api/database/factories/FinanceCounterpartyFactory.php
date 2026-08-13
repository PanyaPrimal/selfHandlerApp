<?php

namespace Database\Factories;

use App\Models\FinanceCounterparty;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinanceCounterparty> */
class FinanceCounterpartyFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'name' => fake()->unique()->company(), 'kind' => 'other', 'note' => null];
    }
}
