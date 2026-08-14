<?php

namespace App\Services\Portability;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class RestoreEligibilityService
{
    public function isEmpty(User $user): bool
    {
        foreach (array_keys(PortabilitySchemaV1::tables()) as $table) {
            if (DB::table($table)->where('user_id', $user->id)->exists()) {
                return false;
            }
        }

        return ! DB::table('attachments')->where('user_id', $user->id)->exists()
            && ! DB::table('notifications')->where('user_id', $user->id)->exists()
            && ! DB::table('integrations')->where('user_id', $user->id)->exists();
    }
}
