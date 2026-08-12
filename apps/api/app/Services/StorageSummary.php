<?php

namespace App\Services;

use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The counts Storage owns.
 *
 * Computed from the items on read rather than cached, so a count can never
 * disagree with what is actually in the list. Analytics and Review will read
 * these numbers instead of recomputing them.
 */
class StorageSummary
{
    public function inboxCount(User $user): int
    {
        return Item::query()
            ->ownedBy($user)
            ->where('status', Item::STATUS_INBOX)
            ->count();
    }

    /**
     * Open and completed counts for every project the user owns.
     *
     * One grouped query for all projects, not one query per project.
     *
     * @return array<int, array{open: int, completed: int}>
     */
    public function projectCounts(User $user): array
    {
        $rows = DB::table('items')
            ->select('project_id', 'status', DB::raw('COUNT(*) as total'))
            ->where('user_id', $user->id)
            ->whereNotNull('project_id')
            ->groupBy('project_id', 'status')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $projectId = (int) $row->project_id;
            $counts[$projectId] ??= ['open' => 0, 'completed' => 0];

            if (in_array($row->status, Item::OPEN_STATUSES, true)) {
                $counts[$projectId]['open'] += (int) $row->total;
            } elseif ($row->status === Item::STATUS_DONE) {
                $counts[$projectId]['completed'] += (int) $row->total;
            }
        }

        return $counts;
    }
}
