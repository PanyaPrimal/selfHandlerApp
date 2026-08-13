<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class WorkoutProgramExercise extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = [
        'user_id', 'workout_program_id', 'exercise_id', 'sort_order', 'target_sets', 'target_reps',
        'starting_weight_kg', 'increment_kg', 'successes_required',
    ];

    protected static function booted(): void
    {
        static::saving(function (WorkoutProgramExercise $row): void {
            $program = WorkoutProgram::query()->find($row->workout_program_id);
            $exercise = Exercise::query()->find($row->exercise_id);
            if (! $program || (int) $program->user_id !== (int) $row->user_id
                || $program->workout_type !== WorkoutProgram::TYPE_STRENGTH) {
                throw new RuntimeException('A prescription requires an owned strength program.');
            }
            if (! $exercise || ! $exercise->isAccessibleTo((int) $row->user_id)) {
                throw new RuntimeException('A prescription exercise must be accessible to its owner.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer', 'target_sets' => 'integer', 'target_reps' => 'integer',
            'starting_weight_kg' => 'decimal:3', 'increment_kg' => 'decimal:3',
            'successes_required' => 'integer',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(WorkoutProgram::class, 'workout_program_id');
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}
