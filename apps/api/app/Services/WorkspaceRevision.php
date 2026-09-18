<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Call while holding the user's row lock in the same transaction as the domain write. */
class WorkspaceRevision
{
    public static function advance(User $user): int
    {
        $next = (int) DB::table('workspace_revisions')->where('user_id', $user->id)->value('revision') + 1;
        DB::table('workspace_revisions')->updateOrInsert(['user_id' => $user->id], ['revision' => $next]);

        return $next;
    }
}
