<?php

namespace App\Services;

use App\Models\RecurringRule;
use App\Models\User;
use App\Models\WorkoutProgram;
use Illuminate\Support\Facades\DB;

/** Translate a WorkoutProgram schedule into the shared recurrence engine. */
class WorkoutProgramRecurrence
{
    public function __construct(private readonly RecurrenceMaterializer $materializer) {}

    /**
     * @param  array<string, mixed>  $schedule
     * @param  list<string>|null  $weekdays
     */
    public function apply(WorkoutProgram $program, User $user, array $schedule, ?array $weekdays): void
    {
        DB::transaction(function () use ($program, $user, $schedule, $weekdays): void {
            $rule = $program->recurringRule;
            $attributes = [];
            if (array_key_exists('schedule_type', $schedule)) {
                $attributes['frequency'] = RecurringRule::frequencyForScheduleType((string) $schedule['schedule_type']);
            }
            foreach (['starts_on', 'ends_on', 'preferred_time'] as $field) {
                if (array_key_exists($field, $schedule)) {
                    $attributes[$field === 'preferred_time' ? 'slot_time' : $field] = $schedule[$field];
                }
            }

            if (! $rule) {
                $rule = RecurringRule::create([
                    'user_id' => $user->id,
                    'owner_type' => RecurringRule::OWNER_WORKOUT_PROGRAM,
                    'owner_id' => $program->id,
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

            $program->setRelation('recurringRule', $rule->refresh());
            $this->materializer->materialize(
                $rule,
                null,
                $program->is_active && ! $program->is_archived,
            );
        });
    }
}
