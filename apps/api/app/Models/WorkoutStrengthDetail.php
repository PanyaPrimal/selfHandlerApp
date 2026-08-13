<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class WorkoutStrengthDetail extends Model
{
    use HasFactory, UserOwned;

    public const MODE_SIMPLE = 'simple';

    public const MODE_DETAILED = 'detailed';

    protected $fillable = ['user_id', 'workout_session_id', 'mode'];

    protected static function booted(): void
    {
        static::saving(function (self $detail): void {
            $session = WorkoutSession::query()->find($detail->workout_session_id);
            if (! $session || (int) $session->user_id !== (int) $detail->user_id
                || $session->workout_type !== WorkoutProgram::TYPE_STRENGTH
                || $session->outcome !== WorkoutSession::OUTCOME_COMPLETED
                || WorkoutEnduranceDetail::query()->where('workout_session_id', $detail->workout_session_id)->exists()
                || WorkoutTimedDetail::query()->where('workout_session_id', $detail->workout_session_id)->exists()) {
                throw new RuntimeException('A strength detail must be the matching session subtype.');
            }
        });
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class, 'workout_session_id');
    }

    public function exercises(): HasMany
    {
        return $this->hasMany(WorkoutSessionExercise::class, 'workout_session_id', 'workout_session_id')
            ->orderBy('sort_order')->orderBy('id');
    }
}
