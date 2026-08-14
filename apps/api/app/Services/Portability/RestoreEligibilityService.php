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

        foreach (['attachments', 'notifications', 'integrations', 'llm_audit_events', 'llm_connections',
            'llm_consents', 'llm_settings', 'llm_tool_confirmations'] as $table) {
            if (DB::table($table)->where('user_id', $user->id)->exists()) {
                return false;
            }
        }

        return true;
    }
}
