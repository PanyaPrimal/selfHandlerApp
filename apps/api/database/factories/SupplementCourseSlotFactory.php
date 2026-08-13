<?php

namespace Database\Factories;

use App\Models\RecurringRule;
use App\Models\RecurringRuleSlot;
use App\Models\SupplementCourse;
use App\Models\SupplementCourseSlot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplementCourseSlot> */
class SupplementCourseSlotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'supplement_course_id' => fn (array $attributes): int => SupplementCourse::factory()
                ->create(['user_id' => $attributes['user_id']])->id,
            'recurring_rule_slot_id' => function (array $attributes): int {
                $rule = RecurringRule::factory()->create([
                    'user_id' => $attributes['user_id'],
                    'owner_type' => RecurringRule::OWNER_SUPPLEMENT_COURSE,
                    'owner_id' => $attributes['supplement_course_id'],
                ]);

                return RecurringRuleSlot::factory()->create([
                    'user_id' => $attributes['user_id'],
                    'recurring_rule_id' => $rule->id,
                ])->id;
            },
            'intake_context' => 'unspecified',
        ];
    }
}
