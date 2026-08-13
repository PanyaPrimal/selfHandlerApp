<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class TrainingGoalDetail extends Model
{
    use HasFactory, UserOwned;

    public const KIND_STRENGTH = 'strength';

    public const KIND_DISTANCE = 'distance';

    public const KIND_RACE = 'race';

    public const KIND_CONSISTENCY = 'consistency';

    public const KINDS = [self::KIND_STRENGTH, self::KIND_DISTANCE, self::KIND_RACE, self::KIND_CONSISTENCY];

    protected $fillable = [
        'user_id', 'goal_id', 'kind', 'exercise_id', 'activity', 'workout_program_id',
        'starting_value', 'target_value',
    ];

    protected $attributes = ['starting_value' => 0];

    protected static function booted(): void
    {
        static::saving(function (self $detail): void {
            $goal = Goal::query()->find($detail->goal_id);
            if (! $goal || (int) $goal->user_id !== (int) $detail->user_id || $goal->type !== Goal::TYPE_TRAINING) {
                throw new RuntimeException('A training detail requires an owned training goal.');
            }
            if ($detail->exercise_id !== null) {
                $exercise = Exercise::query()->find($detail->exercise_id);
                if (! $exercise || ! $exercise->isAccessibleTo((int) $detail->user_id)) {
                    throw new RuntimeException('A training goal exercise must be accessible to its owner.');
                }
            }
            if ($detail->workout_program_id !== null && ! WorkoutProgram::query()
                ->ownedBy((int) $detail->user_id)->whereKey($detail->workout_program_id)->exists()) {
                throw new RuntimeException('A training goal program must have the same owner.');
            }
            if ($detail->exists && $detail->isDirty([
                'user_id', 'goal_id', 'kind', 'exercise_id', 'activity', 'workout_program_id', 'starting_value',
            ])) {
                throw new RuntimeException('A training goal kind, scope, and starting value are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return ['starting_value' => 'decimal:3', 'target_value' => 'decimal:3'];
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(WorkoutProgram::class, 'workout_program_id');
    }
}
