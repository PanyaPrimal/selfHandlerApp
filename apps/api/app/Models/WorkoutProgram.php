<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class WorkoutProgram extends Model
{
    use HasFactory, UserOwned;

    public const TYPE_STRENGTH = 'strength';

    public const TYPE_CARDIO = 'cardio';

    public const TYPE_FLEXIBILITY = 'flexibility';

    public const TYPE_SPORT = 'sport';

    public const TYPES = [self::TYPE_STRENGTH, self::TYPE_CARDIO, self::TYPE_FLEXIBILITY, self::TYPE_SPORT];

    public const INTENSITY_LIGHT = 'light';

    public const INTENSITY_MODERATE = 'moderate';

    public const INTENSITY_VIGOROUS = 'vigorous';

    public const INTENSITIES = [self::INTENSITY_LIGHT, self::INTENSITY_MODERATE, self::INTENSITY_VIGOROUS];

    protected $fillable = [
        'user_id', 'name', 'description', 'workout_type', 'intensity', 'planned_duration_seconds',
        'is_active', 'is_archived', 'archived_at',
    ];

    protected $attributes = ['is_active' => true, 'is_archived' => false];

    protected static function booted(): void
    {
        static::updating(function (WorkoutProgram $program): void {
            if ($program->isDirty('workout_type')) {
                throw new RuntimeException('A workout program type cannot change after creation.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'planned_duration_seconds' => 'integer',
            'is_active' => 'boolean',
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function recurringRule(): HasOne
    {
        return $this->hasOne(RecurringRule::class, 'owner_id')
            ->where('owner_type', RecurringRule::OWNER_WORKOUT_PROGRAM);
    }

    public function exercises(): HasMany
    {
        return $this->hasMany(WorkoutProgramExercise::class)->orderBy('sort_order')->orderBy('id');
    }

    public function enduranceDetail(): HasOne
    {
        return $this->hasOne(WorkoutProgramEnduranceDetail::class);
    }

    public function timedDetail(): HasOne
    {
        return $this->hasOne(WorkoutProgramTimedDetail::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(WorkoutSession::class);
    }

    /** @param array<string, mixed> $attributes */
    public function applyLifecycle(array $attributes): void
    {
        $wasArchived = $this->is_archived;
        $this->fill($attributes);
        if ($this->is_archived) {
            $this->is_active = false;
            if (! $wasArchived || $this->archived_at === null) {
                $this->archived_at = now();
            }
        } else {
            $this->archived_at = null;
        }
    }
}
