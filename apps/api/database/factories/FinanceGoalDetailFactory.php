<?php

namespace Database\Factories;

use App\Models\FinanceGoalDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinanceGoalDetail> */
class FinanceGoalDetailFactory extends Factory
{
    public function definition(): array
    {
        return ['currency_code' => 'UAH'];
    }
}
