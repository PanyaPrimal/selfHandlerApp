<?php

namespace App\Models;

use App\Support\UserOwned;
use App\ValueObjects\WeekdayCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }
}
