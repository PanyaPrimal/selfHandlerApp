<?php

namespace App\Services\Planner;

use App\Models\Item;
use App\Models\PlannedOccurrence;
use App\Models\TimeBlock;
use App\Models\User;

class PlannerPeriodSummaryService
{
    /** @return array<string,int|float|null> */
    public function summarize(User $user, string $from, string $to): array
    {
        $statuses = PlannedOccurrence::query()->ownedBy($user)
            ->where(function ($query) use ($from, $to): void {
                $query->where(function ($original) use ($from, $to): void {
                    $original->whereBetween('occurrence_date', [$from, $to])->whereNull('rescheduled_to');
                })->orWhereBetween('rescheduled_to', [$from, $to]);
            })->pluck('status');
        $items = Item::query()->ownedBy($user)->whereBetween('due_on', [$from, $to])->get(['status']);
        $scheduled = $statuses->count() + $items->count();
        $done = $statuses->where(PlannedOccurrence::STATUS_DONE)->count()
            + $items->where('status', Item::STATUS_DONE)->count();
        $skipped = $statuses->where(PlannedOccurrence::STATUS_SKIPPED)->count()
            + $items->where('status', Item::STATUS_DROPPED)->count();

        return [
            'scheduled' => $scheduled, 'done' => $done, 'skipped' => $skipped,
            'pending' => max(0, $scheduled - $done - $skipped),
            'completion_rate' => $scheduled === 0 ? null : round($done / $scheduled * 100, 2),
            'time_blocks' => TimeBlock::query()->ownedBy($user)->whereBetween('block_date', [$from, $to])->count(),
            'due_items' => $items->count(),
            'open_blockers' => Item::query()->ownedBy($user)->where('is_blocker', true)
                ->whereIn('status', Item::OPEN_STATUSES)->whereBetween('due_on', [$from, $to])->count(),
        ];
    }
}
