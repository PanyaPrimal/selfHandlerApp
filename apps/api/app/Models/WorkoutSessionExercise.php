<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class WorkoutSessionExercise extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = [
        'user_id', 'workout_session_id', 'exercise_id', 'sort_order',
        'simple_weight_kg', 'simple_reps', 'note',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $row): void {
            $session = WorkoutSession::query()->find($row->workout_session_id);
            $exercise = Exercise::query()->find($row->exercise_id);
            if (! $session || (int) $session->user_id !== (int) $row->user_id
                || $session->workout_type !== WorkoutProgram::TYPE_STRENGTH) {
                throw new RuntimeException('A session exercise requires an owned strength session.');
            }
            if (! $exercise || ! $exercise->isAccessibleTo((int) $row->user_id)) {
                throw new RuntimeException('A session exercise must be accessible to its owner.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer', 'simple_weight_kg' => 'decimal:3', 'simple_reps' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class, 'workout_session_id');
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function sets(): HasMany
    {
        return $this->hasMany(WorkoutSet::class)->orderBy('set_order')->orderBy('id');
    }
}
