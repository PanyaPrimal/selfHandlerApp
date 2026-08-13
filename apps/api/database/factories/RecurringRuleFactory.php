<?php

namespace Database\Factories;

use App\Models\RecurringRule;
use App\Models\SupplementCourse;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecurringRule> */
class RecurringRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'owner_type' => RecurringRule::OWNER_SUPPLEMENT_COURSE,
            'owner_id' => fn (array $attributes): int => SupplementCourse::factory()
                ->create(['user_id' => $attributes['user_id']])->id,
            'frequency' => RecurringRule::FREQUENCY_DAILY,
            'interval_count' => 1,
            'cycle_on_days' => null,
            'cycle_off_days' => null,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(29)->toDateString(),
            'timezone' => 'UTC',
            'slot_time' => '08:00',
            'last_materialized_until' => null,
        ];
    }
}
