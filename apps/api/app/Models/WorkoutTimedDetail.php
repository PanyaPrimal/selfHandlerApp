<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class WorkoutTimedDetail extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = ['user_id', 'workout_session_id', 'activity_name'];

    protected static function booted(): void
    {
        static::saving(function (self $detail): void {
            $session = WorkoutSession::query()->find($detail->workout_session_id);
            if (! $session || (int) $session->user_id !== (int) $detail->user_id
                || ! in_array($session->workout_type, [WorkoutProgram::TYPE_FLEXIBILITY, WorkoutProgram::TYPE_SPORT], true)
                || $session->outcome !== WorkoutSession::OUTCOME_COMPLETED
                || WorkoutStrengthDetail::query()->where('workout_session_id', $detail->workout_session_id)->exists()
                || WorkoutEnduranceDetail::query()->where('workout_session_id', $detail->workout_session_id)->exists()) {
                throw new RuntimeException('A timed detail must be the matching session subtype.');
            }
        });
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class, 'workout_session_id');
    }
}
