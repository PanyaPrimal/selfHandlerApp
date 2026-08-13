<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class WorkoutEnduranceDetail extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = [
        'user_id', 'workout_session_id', 'activity', 'run_type', 'distance_m',
        'average_heart_rate', 'energy_kcal',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $detail): void {
            $session = WorkoutSession::query()->find($detail->workout_session_id);
            if (! $session || (int) $session->user_id !== (int) $detail->user_id
                || $session->workout_type !== WorkoutProgram::TYPE_CARDIO
                || $session->outcome !== WorkoutSession::OUTCOME_COMPLETED
                || WorkoutStrengthDetail::query()->where('workout_session_id', $detail->workout_session_id)->exists()
                || WorkoutTimedDetail::query()->where('workout_session_id', $detail->workout_session_id)->exists()) {
                throw new RuntimeException('An endurance detail must be the matching session subtype.');
            }
        });
    }

    protected function casts(): array
    {
        return ['distance_m' => 'integer', 'average_heart_rate' => 'integer', 'energy_kcal' => 'integer'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class, 'workout_session_id');
    }
}
