<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class LlmConsent extends Model
{
    use UserOwned;

    public const SCOPE_STORAGE_INBOX = 'storage_inbox';

    public const SCOPES = [self::SCOPE_STORAGE_INBOX];

    protected $fillable = ['user_id', 'scope', 'granted_at', 'revoked_at'];

    protected static function booted(): void
    {
        static::saving(function (LlmConsent $consent): void {
            if (! in_array($consent->scope, self::SCOPES, true)
                || ($consent->granted_at === null && $consent->revoked_at === null)
                || ($consent->granted_at !== null && $consent->revoked_at !== null)) {
                throw new LogicException('LLM consent is outside the closed current-state contract.');
            }
        });
    }

    public function isGranted(): bool
    {
        return $this->granted_at !== null && $this->revoked_at === null;
    }

    protected function casts(): array
    {
        return ['granted_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
