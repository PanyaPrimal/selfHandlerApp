<?php

namespace Database\Factories;

use App\Models\FinanceCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinanceCategory> */
class FinanceCategoryFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'user_id' => User::factory(),
            'direction' => fake()->randomElement(FinanceCategory::DIRECTIONS),
            'parent_id' => null,
            'parent_scope' => 0,
            'builtin_key' => null,
            'name' => $name,
            'name_normalized' => mb_strtolower($name),
            'archived_at' => null,
        ];
    }
}
