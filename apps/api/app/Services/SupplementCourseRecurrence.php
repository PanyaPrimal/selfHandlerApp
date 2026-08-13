<?php

namespace App\Services;

use App\Models\RecurringRule;
use App\Models\RecurringRuleSlot;
use App\Models\SupplementCourse;
use App\Models\SupplementCourseSlot;
use App\Models\User;

class SupplementCourseRecurrence
{
    public function __construct(private readonly RecurrenceMaterializer $materializer) {}

    /** @param array<string, mixed>|null $schedule */
    public function apply(SupplementCourse $course, User $user, ?array $schedule): void
    {
        $rule = $course->recurringRule;
        if (! $rule) {
            $rule = RecurringRule::create([
                'user_id' => $user->id,
                'owner_type' => RecurringRule::OWNER_SUPPLEMENT_COURSE,
                'owner_id' => $course->id,
                'frequency' => $schedule['frequency'],
                'interval_count' => $schedule['interval_count'],
                'cycle_on_days' => $schedule['cycle']['on_days'] ?? null,
                'cycle_off_days' => $schedule['cycle']['off_days'] ?? null,
                'starts_on' => $course->starts_on,
                'ends_on' => $course->ends_on,
                'timezone' => $user->calendarTimezone(),
                'slot_time' => null,
            ]);
        } else {
            $attributes = [
                'starts_on' => $course->starts_on,
                'ends_on' => $course->ends_on,
                'timezone' => $user->calendarTimezone(),
            ];
            if ($schedule !== null) {
                $attributes = [
                    ...$attributes,
                    'frequency' => $schedule['frequency'],
                    'interval_count' => $schedule['interval_count'],
                    'cycle_on_days' => $schedule['cycle']['on_days'] ?? null,
                    'cycle_off_days' => $schedule['cycle']['off_days'] ?? null,
                ];
            }
            $rule->update($attributes);
        }

        if ($schedule !== null) {
            $rule->syncWeekdays($schedule['weekdays']);
            $rule->ruleSlots()->delete();
            foreach (array_values($schedule['slots']) as $order => $input) {
                $slot = RecurringRuleSlot::create([
                    'user_id' => $user->id,
                    'recurring_rule_id' => $rule->id,
                    'slot' => $input['slot'],
                    'occurrence_time' => $input['time'],
                    'sort_order' => $order,
                ]);
                SupplementCourseSlot::create([
                    'user_id' => $user->id,
                    'supplement_course_id' => $course->id,
                    'recurring_rule_slot_id' => $slot->id,
                    'intake_context' => $input['intake_context'],
                ]);
            }
        }

        $course->setRelation('recurringRule', $rule->refresh());
        $this->materializer->materialize(
            $rule->fresh(['ruleWeekdays', 'ruleSlots']),
            null,
            $course->is_active && ! $course->is_archived,
        );
    }
}
