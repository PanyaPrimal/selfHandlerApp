<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'habit_log_id',
        'sleep_log_id',
        'workout_session_id',
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

            if (collect([
                $occurrence->routine_log_id,
                $occurrence->habit_log_id,
                $occurrence->sleep_log_id,
                $occurrence->workout_session_id,
            ])->filter(fn ($id): bool => $id !== null)->count() > 1) {
                throw new RuntimeException('An occurrence may link to only one domain fact.');
            }

            $factOwnerId = match (true) {
                $occurrence->routine_log_id !== null => RoutineLog::query()
                    ->whereKey($occurrence->routine_log_id)->value('user_id'),
                $occurrence->habit_log_id !== null => HabitLog::query()
                    ->whereKey($occurrence->habit_log_id)->value('user_id'),
                $occurrence->sleep_log_id !== null => SleepLog::query()
                    ->whereKey($occurrence->sleep_log_id)->value('user_id'),
                $occurrence->workout_session_id !== null => WorkoutSession::query()
                    ->whereKey($occurrence->workout_session_id)->value('user_id'),
                default => $occurrence->user_id,
            };

            if ((int) $factOwnerId !== (int) $occurrence->user_id) {
                throw new RuntimeException('An occurrence must have the same owner as its fact.');
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

    public function habitLog(): BelongsTo
    {
        return $this->belongsTo(HabitLog::class);
    }

    public function sleepLog(): BelongsTo
    {
        return $this->belongsTo(SleepLog::class);
    }

    public function workoutSession(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class);
    }

    public function sleepDetail(): HasOne
    {
        return $this->hasOne(SleepOccurrenceDetail::class);
    }

    public function hasFact(): bool
    {
        return $this->routine_log_id !== null
            || $this->habit_log_id !== null
            || $this->sleep_log_id !== null
            || $this->workout_session_id !== null;
    }
}
