<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class WorkoutSession extends Model
{
    use HasFactory, UserOwned;

    public const OUTCOME_COMPLETED = 'completed';

    public const OUTCOME_SKIPPED = 'skipped';

    protected $fillable = [
        'user_id', 'workout_program_id', 'name', 'workout_type', 'outcome', 'performed_on',
        'started_at', 'duration_seconds', 'note',
    ];

    protected $attributes = ['outcome' => self::OUTCOME_COMPLETED];

    protected static function booted(): void
    {
        static::saving(function (WorkoutSession $session): void {
            if ($session->workout_program_id !== null) {
                $program = WorkoutProgram::query()->find($session->workout_program_id);
                if (! $program || (int) $program->user_id !== (int) $session->user_id
                    || $program->workout_type !== $session->workout_type) {
                    throw new RuntimeException('A planned session must match its owned program.');
                }
            }
            if ($session->exists && $session->isDirty(['user_id', 'workout_program_id', 'workout_type'])) {
                throw new RuntimeException('A workout fact owner and type are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'performed_on' => 'date:Y-m-d', 'started_at' => 'datetime', 'duration_seconds' => 'integer',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(WorkoutProgram::class, 'workout_program_id');
    }

    public function strengthDetail(): HasOne
    {
        return $this->hasOne(WorkoutStrengthDetail::class);
    }

    public function enduranceDetail(): HasOne
    {
        return $this->hasOne(WorkoutEnduranceDetail::class);
    }

    public function timedDetail(): HasOne
    {
        return $this->hasOne(WorkoutTimedDetail::class);
    }

    public function plannedOccurrence(): HasOne
    {
        return $this->hasOne(PlannedOccurrence::class, 'workout_session_id');
    }
}
