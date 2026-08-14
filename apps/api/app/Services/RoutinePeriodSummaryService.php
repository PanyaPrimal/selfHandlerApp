<?php

namespace App\Services;

use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\User;

class RoutinePeriodSummaryService
{
    /** @return array{scheduled:int,done:int,skipped:int,pending:int,completion_rate:?float} */
    public function summarize(User $user, string $from, string $to): array
    {
        $statuses = PlannedOccurrence::query()->ownedBy($user)
            ->whereIn('recurring_rule_id', RecurringRule::query()->ownedBy($user)
                ->where('owner_type', RecurringRule::OWNER_ROUTINE)->select('id'))
            ->where(function ($query) use ($from, $to): void {
                $query->where(function ($original) use ($from, $to): void {
                    $original->whereBetween('occurrence_date', [$from, $to])->whereNull('rescheduled_to');
                })->orWhereBetween('rescheduled_to', [$from, $to]);
            })->pluck('status');

        return $this->statusSummary($statuses->count(), $statuses->where(PlannedOccurrence::STATUS_DONE)->count(),
            $statuses->where(PlannedOccurrence::STATUS_SKIPPED)->count());
    }

    /** @return array{scheduled:int,done:int,skipped:int,pending:int,completion_rate:?float} */
    private function statusSummary(int $scheduled, int $done, int $skipped): array
    {
        return [
            'scheduled' => $scheduled, 'done' => $done, 'skipped' => $skipped,
            'pending' => max(0, $scheduled - $done - $skipped),
            'completion_rate' => $scheduled === 0 ? null : round($done / $scheduled * 100, 2),
        ];
    }
}
