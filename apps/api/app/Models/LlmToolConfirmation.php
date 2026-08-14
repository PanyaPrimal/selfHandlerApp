<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LlmToolConfirmation extends Model
{
    use UserOwned;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPLIED, self::STATUS_REJECTED];

    protected $fillable = [
        'user_id', 'llm_connection_id', 'token_hash', 'proposal_hash', 'tool_name', 'source_type',
        'source_id', 'source_fingerprint', 'status', 'expires_at', 'applied_at', 'rejected_at',
    ];

    protected $hidden = ['user_id', 'token_hash', 'proposal_hash', 'source_fingerprint'];

    protected static function booted(): void
    {
        static::saving(function (LlmToolConfirmation $confirmation): void {
            $owner = LlmConnection::query()->whereKey($confirmation->llm_connection_id)->value('user_id');
            $validLifecycle = match ($confirmation->status) {
                self::STATUS_PENDING => $confirmation->applied_at === null && $confirmation->rejected_at === null,
                self::STATUS_APPLIED => $confirmation->applied_at !== null && $confirmation->rejected_at === null,
                self::STATUS_REJECTED => $confirmation->applied_at === null && $confirmation->rejected_at !== null,
                default => false,
            };
            if ((int) $owner !== (int) $confirmation->user_id
                || $confirmation->tool_name !== 'storage_triage_inbox_item'
                || $confirmation->source_type !== 'item'
                || ! in_array($confirmation->status, self::STATUSES, true)
                || ! $validLifecycle
                || (int) $confirmation->source_id < 1
                || ! preg_match('/^[a-f0-9]{64}$/', (string) $confirmation->token_hash)
                || ! preg_match('/^[a-f0-9]{64}$/', (string) $confirmation->proposal_hash)
                || ! preg_match('/^[a-f0-9]{64}$/', (string) $confirmation->source_fingerprint)) {
                throw new LogicException('LLM tool confirmation is outside the supported contract.');
            }
        });
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isFuture();
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(LlmConnection::class, 'llm_connection_id');
    }
}
