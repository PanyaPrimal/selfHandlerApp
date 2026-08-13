<?php

namespace Database\Factories;

use App\Models\Goal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Goal> */
class GoalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->sentence(3),
            'description' => null,
            'type' => Goal::TYPE_GENERAL,
            'status' => 'active',
            'target_date' => null,
            'is_archived' => false,
        ];
    }
}
