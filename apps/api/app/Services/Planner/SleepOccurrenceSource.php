<?php

namespace App\Services\Planner;

use App\Contracts\SchedulableSource;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\SleepPlan;
use App\Models\User;
use App\Support\PlannerEntry;

class SleepOccurrenceSource implements SchedulableSource
{
    public function name(): string
    {
        return 'sleep';
    }

    public function entriesFor(User $user, string $date): array
    {
        $occurrences = PlannedOccurrence::query()
            ->ownedBy($user)
            ->where(function ($query) use ($date): void {
                $query->where(function ($original) use ($date): void {
                    $original->where('occurrence_date', $date)->whereNull('rescheduled_to');
                })->orWhere('rescheduled_to', $date);
            })
            ->whereIn('recurring_rule_id', RecurringRule::query()
                ->ownedBy($user)
                ->where('owner_type', RecurringRule::OWNER_SLEEP_PLAN)
                ->select('id'))
            ->with(['recurringRule', 'sleepDetail'])
            ->orderBy('occurrence_time')
            ->orderBy('id')
            ->get();
        $plans = SleepPlan::query()->ownedBy($user)
            ->whereIn('id', $occurrences->pluck('recurringRule.owner_id')->filter())
            ->get(['id', 'name', 'is_archived'])
            ->keyBy('id');

        return $occurrences->map(function (PlannedOccurrence $occurrence) use ($plans, $date): ?PlannerEntry {
            $plan = $plans->get($occurrence->recurringRule?->owner_id);
            if (! $plan || $plan->is_archived) {
                return null;
            }

            return new PlannerEntry(
                source: $this->name(),
                sourceId: $occurrence->id,
                title: $plan->name,
                time: $occurrence->occurrence_time ? substr((string) $occurrence->occurrence_time, 0, 5) : null,
                status: $occurrence->status,
                actions: $occurrence->hasFact() ? [] : ['reschedule'],
                meta: [
                    'sleep_plan_id' => $plan->id,
                    'planned_wake_time' => $occurrence->sleepDetail?->planned_wake_time
                        ? substr((string) $occurrence->sleepDetail->planned_wake_time, 0, 5)
                        : null,
                    'occurrence_date' => $occurrence->occurrence_date->format('Y-m-d'),
                    'rescheduled_to' => $occurrence->rescheduled_to?->format('Y-m-d'),
                    'action_url' => '/routines?sleep_date='.$date,
                ],
            );
        })->filter()->values()->all();
    }
}
