<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class LlmAuditEvent extends Model
{
    use UserOwned;

    public const EVENT_CONNECTION_CREATED = 'connection_created';

    public const EVENT_CONNECTION_UPDATED = 'connection_updated';

    public const EVENT_CONNECTION_TESTED = 'connection_tested';

    public const EVENT_CONNECTION_ACTIVATED = 'connection_activated';

    public const EVENT_CONNECTION_DELETED = 'connection_deleted';

    public const EVENT_CONSENT_GRANTED = 'consent_granted';

    public const EVENT_CONSENT_REVOKED = 'consent_revoked';

    public const EVENT_DRAFT_ACCEPTED = 'draft_accepted';

    public const EVENT_DRAFT_REJECTED = 'draft_rejected';

    public const EVENT_CONFIRMATION_APPLIED = 'confirmation_applied';

    public const EVENT_CONFIRMATION_REJECTED = 'confirmation_rejected';

    public const EVENTS = [
        self::EVENT_CONNECTION_CREATED, self::EVENT_CONNECTION_UPDATED, self::EVENT_CONNECTION_TESTED,
        self::EVENT_CONNECTION_ACTIVATED, self::EVENT_CONNECTION_DELETED, self::EVENT_CONSENT_GRANTED,
        self::EVENT_CONSENT_REVOKED, self::EVENT_DRAFT_ACCEPTED, self::EVENT_DRAFT_REJECTED,
        self::EVENT_CONFIRMATION_APPLIED, self::EVENT_CONFIRMATION_REJECTED,
    ];

    public const OUTCOME_SUCCEEDED = 'succeeded';

    public const OUTCOME_REJECTED = 'rejected';

    protected $fillable = [
        'user_id', 'llm_connection_id', 'event', 'scope', 'outcome', 'error_code', 'occurred_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (LlmAuditEvent $event): void {
            if ($event->exists
                || ! in_array($event->event, self::EVENTS, true)
                || ($event->scope !== null && ! in_array($event->scope, LlmConsent::SCOPES, true))
                || ! in_array($event->outcome, [self::OUTCOME_SUCCEEDED, self::OUTCOME_REJECTED], true)) {
                throw new LogicException('LLM audit events are immutable and closed.');
            }
        });
        static::deleting(function (): void {
            throw new LogicException('LLM audit events are append-only.');
        });
    }

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }
}
