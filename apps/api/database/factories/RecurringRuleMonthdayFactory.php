<?php

namespace Database\Factories;

use App\Models\FinanceRecurringOperation;
use App\Models\RecurringRule;
use App\Models\RecurringRuleMonthday;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RecurringRuleMonthday> */
class RecurringRuleMonthdayFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'recurring_rule_id' => function (array $attributes): int {
                $operation = FinanceRecurringOperation::factory()->create(['user_id' => $attributes['user_id']]);

                return RecurringRule::factory()->create([
                    'user_id' => $attributes['user_id'],
                    'owner_type' => RecurringRule::OWNER_FINANCE_RECURRING_OPERATION,
                    'owner_id' => $operation->id,
                    'frequency' => RecurringRule::FREQUENCY_MONTHLY,
                ])->id;
            },
            'monthday' => 15,
        ];
    }
}
