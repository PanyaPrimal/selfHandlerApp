<?php

namespace App\Services\Ai;

use App\Models\LlmAuditEvent;
use App\Models\LlmConnection;
use App\Models\User;

class LlmAuditLogger
{
    public function record(
        User $user,
        string $event,
        string $outcome = LlmAuditEvent::OUTCOME_SUCCEEDED,
        ?LlmConnection $connection = null,
        ?string $scope = null,
        ?string $errorCode = null,
    ): LlmAuditEvent {
        return LlmAuditEvent::query()->create([
            'user_id' => $user->id,
            'llm_connection_id' => $connection?->id,
            'event' => $event,
            'scope' => $scope,
            'outcome' => $outcome,
            'error_code' => $errorCode,
            'occurred_at' => now(),
        ]);
    }
}
