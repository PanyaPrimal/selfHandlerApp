<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InAppNotification extends Model
{
    use HasFactory, UserOwned;

    public const CHANNEL_IN_APP = 'in_app';

    public const CHANNEL_ANDROID_LOCAL = 'android_local';

    public const CHANNELS = [self::CHANNEL_IN_APP, self::CHANNEL_ANDROID_LOCAL];

    public const SOURCE_PLANNED_OCCURRENCE = 'planned_occurrence';

    public const SOURCE_STORAGE_ITEM = 'storage_item';

    public const SOURCE_DAILY_DIGEST = 'daily_digest';

    public const SOURCE_SUPPLEMENT_RESTOCK_PROPOSAL = 'supplement_restock_proposal';

    public const TYPE_ROUTINE_REMINDER = 'routine_reminder';

    public const TYPE_HABIT_REMINDER = 'habit_reminder';

    public const TYPE_SLEEP_REMINDER = 'sleep_reminder';

    public const TYPE_WORKOUT_REMINDER = 'workout_reminder';

    public const TYPE_STORAGE_DUE = 'storage_due';

    public const TYPE_DAILY_DIGEST = 'daily_digest';

    public const TYPE_SUPPLEMENT_INTAKE = 'supplement_intake';

    public const TYPE_SUPPLEMENT_RESTOCK = 'supplement_restock';

    public const TYPES = [
        self::TYPE_ROUTINE_REMINDER,
        self::TYPE_HABIT_REMINDER,
        self::TYPE_SLEEP_REMINDER,
        self::TYPE_WORKOUT_REMINDER,
        self::TYPE_STORAGE_DUE,
        self::TYPE_DAILY_DIGEST,
        self::TYPE_SUPPLEMENT_INTAKE,
        self::TYPE_SUPPLEMENT_RESTOCK,
    ];

    public const CATEGORY_ROUTINE = 'routine';

    public const CATEGORY_STORAGE = 'storage';

    public const CATEGORY_HABIT = 'habit';

    public const CATEGORY_SLEEP = 'sleep';

    public const CATEGORY_WORKOUT = 'workout';

    public const CATEGORY_DIGEST = 'digest';

    public const CATEGORY_SUPPLEMENT = 'supplement';

    public const CATEGORIES = [
        self::CATEGORY_ROUTINE,
        self::CATEGORY_HABIT,
        self::CATEGORY_SLEEP,
        self::CATEGORY_WORKOUT,
        self::CATEGORY_STORAGE,
        self::CATEGORY_DIGEST,
        self::CATEGORY_SUPPLEMENT,
    ];

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENT = 'sent';

    public const STATUS_READ = 'read';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_SNOOZED = 'snoozed';

    public const STATUS_ACTIONED = 'actioned';

    public const STATUS_CANCELLED = 'cancelled';

    public const VISIBLE_STATUSES = [self::STATUS_SENT, self::STATUS_READ];

    public const ACTIVE_STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_SENT,
        self::STATUS_READ,
        self::STATUS_SNOOZED,
    ];

    protected $table = 'notifications';

    protected $fillable = [
        'user_id', 'source_type', 'source_id', 'type', 'category', 'title', 'body', 'action_url',
        'content', 'scheduled_at', 'status', 'channels', 'escalation_count', 'next_escalation_at',
        'max_escalations', 'snoozed_until', 'sent_at', 'read_at', 'dismissed_at', 'actioned_at',
        'cancelled_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_SCHEDULED,
        'escalation_count' => 0,
        'max_escalations' => 0,
    ];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'content' => 'array',
            'channels' => 'array',
            'scheduled_at' => 'immutable_datetime',
            'next_escalation_at' => 'immutable_datetime',
            'snoozed_until' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
            'dismissed_at' => 'immutable_datetime',
            'actioned_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'escalation_count' => 'integer',
            'max_escalations' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isVisible(): bool
    {
        return in_array($this->status, self::VISIBLE_STATUSES, true);
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'category' => $this->category,
            'subject' => is_string($this->content['title'] ?? null) ? $this->content['title'] : null,
            'title' => (string) $this->title,
            'body' => (string) $this->body,
            'action_url' => $this->action_url,
            'status' => $this->status,
            'channels' => $this->channels ?? [],
            'escalation_count' => $this->escalation_count,
            'sent_at' => $this->sent_at?->toISOString(),
            'read_at' => $this->read_at?->toISOString(),
        ];
    }
}
