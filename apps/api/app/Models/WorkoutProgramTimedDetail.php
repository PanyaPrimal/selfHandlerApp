<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class WorkoutProgramTimedDetail extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = ['user_id', 'workout_program_id', 'activity_name'];

    protected static function booted(): void
    {
        static::saving(function (self $detail): void {
            $program = WorkoutProgram::query()->find($detail->workout_program_id);
            if (! $program || (int) $program->user_id !== (int) $detail->user_id
                || ! in_array($program->workout_type, [WorkoutProgram::TYPE_FLEXIBILITY, WorkoutProgram::TYPE_SPORT], true)) {
                throw new RuntimeException('A timed detail requires an owned flexibility or sport program.');
            }
        });
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(WorkoutProgram::class, 'workout_program_id');
    }
}
