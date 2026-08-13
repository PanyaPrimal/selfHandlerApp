<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class HabitLimitStep extends Model
{
    use HasFactory, UserOwned;

    public const PERIOD_DAY = 'day';

    public const PERIOD_WEEK = 'week';

    public const PERIODS = [self::PERIOD_DAY, self::PERIOD_WEEK];

    protected $fillable = ['user_id', 'habit_id', 'effective_on', 'limit_value', 'period'];

    protected static function booted(): void
    {
        static::saving(function (HabitLimitStep $step): void {
            if (blank($step->user_id)) {
                return;
            }

            $habit = Habit::query()->whereKey($step->habit_id)->first();
            if (! $habit
                || (int) $habit->user_id !== (int) $step->user_id
                || $habit->mode !== Habit::MODE_STEPPED_LIMIT) {
                throw new RuntimeException('A limit step requires an owned stepped-limit habit.');
            }

            if ((float) $step->limit_value <= 0 || ! in_array($step->period, self::PERIODS, true)) {
                throw new RuntimeException('A limit step requires a positive supported ceiling.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'effective_on' => 'date:Y-m-d',
            'limit_value' => 'decimal:3',
        ];
    }

    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }

    public function normalizedDailyRate(): float
    {
        return (float) $this->limit_value / ($this->period === self::PERIOD_WEEK ? 7 : 1);
    }
}
