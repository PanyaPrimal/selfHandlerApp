<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A materialized future day belonging to a rule.
 *
 * The rule's expansion, not this table, answers whether a day is scheduled. An
 * occurrence exists so a specific future day has a durable identity that a later
 * feature can reschedule or remind about, and so a completed day can point at
 * the domain fact that satisfied it.
 *
 * `status` is derived from that fact and is rebuilt by `recurrence:reconcile`;
 * `routine_logs` remains the authoritative record of what actually happened.
 */
class PlannedOccurrence extends Model
{
    use HasFactory, UserOwned;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_DONE = 'done';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'user_id',
        'recurring_rule_id',
        'occurrence_date',
        'rescheduled_to',
        'slot',
        'occurrence_time',
        'status',
        'routine_log_id',
        'materialized_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (PlannedOccurrence $occurrence): void {
            if (blank($occurrence->user_id)) {
                return;
            }

            $ruleOwnerId = RecurringRule::query()
                ->whereKey($occurrence->recurring_rule_id)
                ->value('user_id');

            if ((int) $ruleOwnerId !== (int) $occurrence->user_id) {
                throw new RuntimeException('An occurrence must have the same owner as its rule.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'occurrence_date' => 'date:Y-m-d',
            'rescheduled_to' => 'date:Y-m-d',
            'materialized_at' => 'datetime',
        ];
    }

    public function recurringRule(): BelongsTo
    {
        return $this->belongsTo(RecurringRule::class);
    }

    public function routineLog(): BelongsTo
    {
        return $this->belongsTo(RoutineLog::class);
    }
}
