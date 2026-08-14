<?php

namespace App\Services\Ai;

use App\Models\LlmAuditEvent;
use App\Models\LlmConsent;
use App\Models\LlmToolConfirmation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LlmConsentService
{
    public function __construct(private readonly LlmAuditLogger $audit) {}

    public function replace(User $user, string $scope, bool $granted): LlmConsent
    {
        return DB::transaction(function () use ($granted, $scope, $user): LlmConsent {
            $consent = LlmConsent::query()->firstOrNew(['user_id' => $user->id, 'scope' => $scope]);
            $consent->granted_at = $granted ? now() : null;
            $consent->revoked_at = $granted ? null : now();
            $consent->save();
            if (! $granted) {
                LlmToolConfirmation::query()->where('user_id', $user->id)
                    ->where('status', LlmToolConfirmation::STATUS_PENDING)
                    ->update([
                        'status' => LlmToolConfirmation::STATUS_REJECTED,
                        'rejected_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
            $this->audit->record(
                $user,
                $granted ? LlmAuditEvent::EVENT_CONSENT_GRANTED : LlmAuditEvent::EVENT_CONSENT_REVOKED,
                scope: $scope,
            );

            return $consent->fresh();
        });
    }

    public function granted(User $user, string $scope): bool
    {
        return LlmConsent::query()->ownedBy($user)->where('scope', $scope)
            ->whereNotNull('granted_at')->whereNull('revoked_at')->exists();
    }
}
