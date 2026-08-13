<?php

namespace Database\Factories;

use App\Models\Supplement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Supplement> */
class SupplementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'category' => fake()->randomElement(Supplement::CATEGORIES),
            'form' => 'capsule',
            'stock_unit' => 'piece',
            'preferred_display_unit' => 'piece',
            'usual_dose_quantity' => '1.000000',
            'package_quantity' => '30.000000',
            'restock_lead_days' => 7,
            'note' => null,
            'is_archived' => false,
            'archived_at' => null,
        ];
    }
}
