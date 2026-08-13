<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class HabitLog extends Model
{
    use HasFactory, UserOwned;

    public const OUTCOME_DONE = 'done';

    public const OUTCOME_NOT_DONE = 'not_done';

    public const OUTCOME_RECORDED = 'recorded';

    public const OUTCOME_PROTECTED = 'protected';

    public const OUTCOME_RELAPSE = 'relapse';

    public const OUTCOME_SKIPPED = 'skipped';

    public const OUTCOMES = [
        self::OUTCOME_DONE,
        self::OUTCOME_NOT_DONE,
        self::OUTCOME_RECORDED,
        self::OUTCOME_PROTECTED,
        self::OUTCOME_RELAPSE,
        self::OUTCOME_SKIPPED,
    ];

    protected $fillable = [
        'user_id', 'habit_id', 'log_date', 'outcome', 'value', 'occurred_at', 'note',
    ];

    protected static function booted(): void
    {
        static::saving(function (HabitLog $log): void {
            if (blank($log->user_id)) {
                return;
            }

            $habit = Habit::query()->whereKey($log->habit_id)->first();
            if (! $habit || (int) $habit->user_id !== (int) $log->user_id) {
                throw new RuntimeException('A habit log must have the same owner as its habit.');
            }

            if (! $habit->acceptsOutcome((string) $log->outcome)) {
                throw new RuntimeException('The outcome is incompatible with the habit mode.');
            }

            $recorded = $log->outcome === self::OUTCOME_RECORDED;
            if (($recorded && ($log->value === null || (float) $log->value < 0))
                || (! $recorded && $log->value !== null)) {
                throw new RuntimeException('The habit log value is incompatible with its outcome.');
            }

            if (($log->outcome === self::OUTCOME_SKIPPED) !== ($log->occurred_at === null)) {
                throw new RuntimeException('Only a skipped habit log may omit its occurrence time.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'log_date' => 'date:Y-m-d',
            'value' => 'decimal:3',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }
}
