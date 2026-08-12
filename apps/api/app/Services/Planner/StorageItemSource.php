<?php

namespace App\Services\Planner;

use App\Contracts\SchedulableSource;
use App\Models\Item;
use App\Models\User;
use App\Support\PlannerEntry;

/**
 * Storage work that carries a due date.
 *
 * A task is not a recurrence: it has one due date, so the only planner action is
 * moving that date, and the move is performed through Storage's own endpoint.
 * Skipping does not apply — "not doing it" is the item status Storage already owns.
 */
class StorageItemSource implements SchedulableSource
{
    public function name(): string
    {
        return 'storage';
    }

    public function entriesFor(User $user, string $date): array
    {
        return Item::query()
            ->ownedBy($user)
            ->where('due_on', $date)
            ->whereIn('status', Item::OPEN_STATUSES)
            ->orderBy('id')
            ->get(['id', 'title', 'type', 'status', 'priority', 'project_id'])
            ->map(fn (Item $item): PlannerEntry => new PlannerEntry(
                source: $this->name(),
                sourceId: $item->id,
                title: $item->title,
                time: null,
                status: $item->status,
                actions: ['move'],
                meta: [
                    'type' => $item->type,
                    'priority' => $item->priority,
                    'project_id' => $item->project_id,
                ],
            ))
            ->all();
    }
}
