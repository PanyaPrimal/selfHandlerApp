<?php

namespace App\Services;

use App\Models\Item;
use Illuminate\Validation\ValidationException;

/**
 * The one place that decides whether an item may be completed.
 *
 * A child marked as a blocker means "this has to be finished first". Only
 * completion is restricted: the title, project, tags and priority of a blocked
 * parent stay editable, because a blocker describes readiness to finish, not a
 * lock on the record.
 *
 * Every path that closes an item consults this, so a second endpoint cannot
 * quietly skip the rule.
 */
class ItemCompletionGuard
{
    /**
     * @throws ValidationException when an open blocking child exists
     */
    public function assertCompletable(Item $item): void
    {
        $blocking = $this->blockingChildren($item);

        if ($blocking === []) {
            return;
        }

        $names = implode(', ', $blocking);

        throw ValidationException::withMessages([
            'status' => "Finish or drop what is blocking this first: {$names}.",
        ]);
    }

    /**
     * Titles of the direct children that still block this item.
     *
     * Direct children only: nesting is one level, so there is no tree to walk.
     *
     * @return list<string>
     */
    public function blockingChildren(Item $item): array
    {
        return $item->children()
            ->where('is_blocker', true)
            ->whereIn('status', Item::OPEN_STATUSES)
            ->orderBy('id')
            ->pluck('title')
            ->all();
    }
}
