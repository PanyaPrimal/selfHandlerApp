<?php

namespace App\Services\Planner;

use App\Contracts\SchedulableSource;
use App\Models\TimeBlock;
use App\Models\User;
use App\Support\PlannerEntry;

/** The user's own blocks of time, which belong to no module. */
class TimeBlockSource implements SchedulableSource
{
    public function name(): string
    {
        return 'time_block';
    }

    public function entriesFor(User $user, string $date): array
    {
        return TimeBlock::query()
            ->ownedBy($user)
            ->where('block_date', $date)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->map(fn (TimeBlock $block): PlannerEntry => new PlannerEntry(
                source: $this->name(),
                sourceId: $block->id,
                title: $block->title,
                time: $block->starts_at ? substr((string) $block->starts_at, 0, 5) : null,
                status: 'planned',
                actions: ['edit', 'delete'],
                meta: [
                    'ends_at' => $block->ends_at ? substr((string) $block->ends_at, 0, 5) : null,
                    'note' => $block->note,
                ],
            ))
            ->all();
    }
}
