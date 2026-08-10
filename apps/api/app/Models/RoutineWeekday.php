<?php

namespace App\Models;

use App\Support\UserOwned;
use App\ValueObjects\WeekdayCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One weekday of a weekday-scheduled routine.
 *
 * This is part of the routine schedule rather than a separately managed
 * concept, so it is written through `Routine::syncWeekdays()`.
 */
class RoutineWeekday extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = [
        'user_id',
        'routine_id',
        'weekday',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => WeekdayCode::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (RoutineWeekday $weekday): void {
            if (blank($weekday->user_id)) {
                return;
            }

            $routineOwnerId = Routine::withTrashed()
                ->whereKey($weekday->routine_id)
                ->value('user_id');

            if ((int) $routineOwnerId !== (int) $weekday->user_id) {
                throw new RuntimeException('A routine weekday must have the same owner as its routine.');
            }
        });
    }

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }
}
