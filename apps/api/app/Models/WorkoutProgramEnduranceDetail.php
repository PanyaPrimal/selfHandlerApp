<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class WorkoutProgramEnduranceDetail extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = ['user_id', 'workout_program_id', 'activity', 'run_type', 'target_distance_m'];

    protected static function booted(): void
    {
        static::saving(function (self $detail): void {
            $program = WorkoutProgram::query()->find($detail->workout_program_id);
            if (! $program || (int) $program->user_id !== (int) $detail->user_id
                || $program->workout_type !== WorkoutProgram::TYPE_CARDIO) {
                throw new RuntimeException('An endurance detail requires an owned cardio program.');
            }
        });
    }

    protected function casts(): array
    {
        return ['target_distance_m' => 'integer'];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(WorkoutProgram::class, 'workout_program_id');
    }
}
