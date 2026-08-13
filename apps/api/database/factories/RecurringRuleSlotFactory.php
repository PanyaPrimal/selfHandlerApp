<?php

namespace Database\Factories;

use App\Models\RecurringRule;
use App\Models\RecurringRuleSlot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecurringRuleSlot> */
class RecurringRuleSlotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'recurring_rule_id' => fn (array $attributes): int => RecurringRule::factory()
                ->create(['user_id' => $attributes['user_id']])->id,
            'slot' => 'morning',
            'occurrence_time' => '08:00',
            'sort_order' => 0,
        ];
    }
}
