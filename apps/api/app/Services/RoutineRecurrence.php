<?php

namespace App\Services;

use App\Models\RecurringRule;
use App\Models\Routine;
use App\Models\User;

/**
 * The routine side of the shared recurrence boundary.
 *
 * The engine deliberately knows nothing about routines, so the translation
 * between the routine API vocabulary (`schedule_type`, `weekdays`,
 * `preferred_time`, `starts_on`, `ends_on`) and a rule lives here, in one place.
 */
class RoutineRecurrence
{
    public function __construct(private readonly RecurrenceMaterializer $materializer) {}

    /**
     * Create or update the routine's single rule and refresh its window.
     *
     * @param  array<string, mixed>  $schedule  only the keys the request supplied
     * @param  list<string>|null  $weekdays  null when the request said nothing
     */
    public function apply(Routine $routine, User $user, array $schedule, ?array $weekdays): void
    {
        $rule = $routine->recurringRule;

        $attributes = array_filter([
            'frequency' => array_key_exists('schedule_type', $schedule)
                ? RecurringRule::frequencyForScheduleType((string) $schedule['schedule_type'])
                : null,
        ], fn ($value): bool => $value !== null);

        foreach (['starts_on', 'ends_on', 'preferred_time'] as $field) {
            if (array_key_exists($field, $schedule)) {
                $attributes[$field === 'preferred_time' ? 'slot_time' : $field] = $schedule[$field];
            }
        }

        if (! $rule) {
            $rule = RecurringRule::create([
                'user_id' => $user->id,
                'owner_type' => RecurringRule::OWNER_ROUTINE,
                'owner_id' => $routine->id,
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

        $routine->setRelation('recurringRule', $rule->refresh());

        $this->materializer->materialize(
            $rule,
            null,
            $routine->is_active && ! $routine->is_archived && ! $routine->trashed(),
        );
    }
}
