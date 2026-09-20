<?php

namespace App\Services;

use App\Models\Habit;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\User;
use Carbon\CarbonImmutable;

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
            $target = $schedule['schedule_type'] === 'weekly_target'
                ? (int) ($schedule['weekly_target'] ?? $habit->weekly_target)
                : null;
            if ($target !== $habit->weekly_target) {
                $history = $habit->weekly_target_history ?? [];
                $week = CarbonImmutable::now($user->calendarTimezone())->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
                $history[$week] = $target;
                ksort($history);
                $habit->forceFill(['weekly_target' => $target, 'weekly_target_history' => $history])->save();
            }
            // Daily occurrences are available check-in dates, not daily obligations.
            // The goal lives on the habit; fixed-day consumers exclude unmarked
            // flexible dates. Existing fact-linked occurrences keep their identity.
            $attributes['frequency'] = RecurringRule::frequencyForScheduleType(
                $schedule['schedule_type'] === 'weekly_target' ? 'daily' : (string) $schedule['schedule_type'],
            );
        } elseif (array_key_exists('weekly_target', $schedule)) {
            $this->apply($habit, $user, [...$schedule, 'schedule_type' => 'weekly_target'], $weekdays);

            return;
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
        if ($habit->weekly_target !== null && $habit->is_active && ! $habit->is_archived) {
            $this->releaseFixedDates($habit, $rule);
            // The user can choose or backfill any eligible day of this week.
            $this->materializer->materialize($rule, CarbonImmutable::now($user->calendarTimezone())
                ->startOfWeek(CarbonImmutable::MONDAY)->toDateString());
        }
        $this->materializer->materialize(
            $rule,
            null,
            $habit->is_active && ! $habit->is_archived,
        );
    }

    /** A free-choice week replaces old date assignments while retaining fact IDs and actual dates. */
    private function releaseFixedDates(Habit $habit, RecurringRule $rule): void
    {
        $moved = PlannedOccurrence::query()->where('recurring_rule_id', $rule->id)
            ->whereNotNull('rescheduled_to')->with('habitLog')->lockForUpdate()->get();
        foreach ($moved as $row) {
            $date = $row->rescheduled_to->format('Y-m-d');
            if ($habit->weeklyTargetForDate($date) === null) {
                continue;
            }
            if ($row->habitLog === null) {
                $row->update(['rescheduled_to' => null]);

                continue;
            }
            // The fact is authoritative. Reuse its occurrence at its actual date;
            // only an unused generated slot can be removed to make room.
            PlannedOccurrence::query()->where('recurring_rule_id', $rule->id)
                ->where('occurrence_date', $date)->where('slot', $row->slot)
                ->whereNull('rescheduled_to')->whereNull('habit_log_id')->delete();
            $row->update(['occurrence_date' => $date, 'rescheduled_to' => null]);
        }
    }
}
