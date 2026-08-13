<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class WorkoutSet extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = [
        'user_id', 'workout_session_exercise_id', 'set_order', 'weight_kg', 'reps', 'rest_seconds',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $set): void {
            $exercise = WorkoutSessionExercise::query()->find($set->workout_session_exercise_id);
            if (! $exercise || (int) $exercise->user_id !== (int) $set->user_id) {
                throw new RuntimeException('A set must have the same owner as its session exercise.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'set_order' => 'integer', 'weight_kg' => 'decimal:3',
            'reps' => 'integer', 'rest_seconds' => 'integer',
        ];
    }

    public function sessionExercise(): BelongsTo
    {
        return $this->belongsTo(WorkoutSessionExercise::class, 'workout_session_exercise_id');
    }
}
