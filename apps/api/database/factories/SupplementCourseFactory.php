<?php

namespace Database\Factories;

use App\Models\Supplement;
use App\Models\SupplementCourse;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplementCourse> */
class SupplementCourseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'supplement_id' => fn (array $attributes): int => Supplement::factory()
                ->create(['user_id' => $attributes['user_id']])->id,
            'goal_id' => null,
            'name' => fake()->words(2, true),
            'dose_quantity' => '1.000000',
            'dose_display_unit' => 'piece',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(29)->toDateString(),
            'is_active' => true,
            'is_archived' => false,
            'archived_at' => null,
        ];
    }
}
