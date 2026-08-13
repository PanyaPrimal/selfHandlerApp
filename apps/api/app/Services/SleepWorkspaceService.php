<?php

namespace App\Services;

use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\SleepPlan;
use App\Models\User;
use Illuminate\Support\Collection;

class SleepWorkspaceService
{
    public function __construct(private readonly SleepStatisticsService $statistics) {}

    /**
     * @param  Collection<int, SleepPlan>  $plans
     * @return Collection<int, SleepPlan>
     */
    public function attachSelectedNights(Collection $plans, User $user, string $date): Collection
    {
        if ($plans->isEmpty()) {
            return $plans;
        }

        $rules = RecurringRule::query()
            ->ownedBy($user)
            ->where('owner_type', RecurringRule::OWNER_SLEEP_PLAN)
            ->whereIn('owner_id', $plans->pluck('id'))
            ->get(['id', 'owner_id'])
            ->keyBy('id');
        $occurrences = PlannedOccurrence::query()
            ->ownedBy($user)
            ->whereIn('recurring_rule_id', $rules->keys())
            ->where(function ($query) use ($date): void {
                $query->where(function ($original) use ($date): void {
                    $original->where('occurrence_date', $date)->whereNull('rescheduled_to');
                })->orWhere('rescheduled_to', $date);
            })
            ->with(['sleepDetail', 'sleepLog'])
            ->get()
            ->keyBy(fn (PlannedOccurrence $occurrence): ?int => $rules->get($occurrence->recurring_rule_id)?->owner_id);

        foreach ($plans as $plan) {
            $occurrence = $occurrences->get($plan->id);
            $plan->setRelation('selectedNightPayload', $occurrence
                ? $this->statistics->nightPayload($occurrence, $plan, $user->calendarTimezone())
                : null);
        }

        return $plans;
    }
}
