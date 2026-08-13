<?php

namespace App\Services;

use App\Models\RecurringRule;
use App\Models\SleepPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SleepPlanRecurrence
{
    public function __construct(private readonly RecurrenceMaterializer $materializer) {}

    /**
     * @param  array<string, mixed>  $schedule
     * @param  list<string>|null  $weekdays
     */
    public function apply(SleepPlan $plan, User $user, array $schedule, ?array $weekdays): void
    {
        DB::transaction(function () use ($plan, $user, $schedule, $weekdays): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->assertSingleActive($plan, $user);

            $rule = $plan->recurringRule;
            $attributes = [];

            if (array_key_exists('schedule_type', $schedule)) {
                $attributes['frequency'] = RecurringRule::frequencyForScheduleType(
                    (string) $schedule['schedule_type'],
                );
            }

            foreach (['starts_on', 'ends_on', 'planned_bed_time'] as $field) {
                if (array_key_exists($field, $schedule)) {
                    $attributes[$field === 'planned_bed_time' ? 'slot_time' : $field] = $schedule[$field];
                }
            }

            if (! $rule) {
                $rule = RecurringRule::create([
                    'user_id' => $user->id,
                    'owner_type' => RecurringRule::OWNER_SLEEP_PLAN,
                    'owner_id' => $plan->id,
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

            $plan->setRelation('recurringRule', $rule->refresh());
            $this->materializer->materialize(
                $rule,
                null,
                $plan->is_active && ! $plan->is_archived,
            );
        });
    }

    private function assertSingleActive(SleepPlan $plan, User $user): void
    {
        if (! $plan->is_active || $plan->is_archived) {
            return;
        }

        if (SleepPlan::query()
            ->ownedBy($user)
            ->whereKeyNot($plan->id)
            ->where('is_active', true)
            ->where('is_archived', false)
            ->exists()) {
            throw ValidationException::withMessages([
                'is_active' => __('messages.sleep_one_active'),
            ]);
        }
    }
}
