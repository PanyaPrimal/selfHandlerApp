<?php

namespace App\Services;

use App\Models\Habit;
use App\Models\RecurringRule;
use App\Models\User;

/** Translate Habit schedule fields into the one shared recurrence engine. */
class HabitRecurrence
{
    public function __construct(private readonly RecurrenceMaterializer $materializer) {}

    /**
     * @param  array<string, mixed>  $schedule
     * @param  list<string>|null  $weekdays
     */
    public function apply(Habit $habit, User $user, array $schedule, ?array $weekdays): void
    {
        $rule = $habit->recurringRule;
        $attributes = [];

        if (array_key_exists('schedule_type', $schedule)) {
            $attributes['frequency'] = RecurringRule::frequencyForScheduleType(
                (string) $schedule['schedule_type'],
            );
        }

        foreach (['starts_on', 'ends_on', 'preferred_time'] as $field) {
            if (array_key_exists($field, $schedule)) {
                $attributes[$field === 'preferred_time' ? 'slot_time' : $field] = $schedule[$field];
            }
        }

        if (! $rule) {
            $rule = RecurringRule::create([
                'user_id' => $user->id,
                'owner_type' => RecurringRule::OWNER_HABIT,
                'owner_id' => $habit->id,
                'frequency' => $attributes['frequency'] ?? RecurringRule::FREQUENCY_DAILY,
                'starts_on' => $attributes['starts_on'] ?? null,
                'ends_on' => $attributes['ends_on'] ?? null,
                'timezone' => $user->calendarTimezone(),
                'slot_time' => $attributes['slot_time'] ?? null,
            ]);
        } elseif ($attributes !== []) {
            $rule->update($attributes);
        }

        if ($weekdays !== null) {
            $rule->syncWeekdays($weekdays);
        } elseif (($attributes['frequency'] ?? null) === RecurringRule::FREQUENCY_DAILY) {
            $rule->syncWeekdays([]);
        }

        $habit->setRelation('recurringRule', $rule->refresh());
        $this->materializer->materialize(
            $rule,
            null,
            $habit->is_active && ! $habit->is_archived,
        );
    }
}
