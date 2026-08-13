<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class Habit extends Model
{
    use HasFactory, UserOwned;

    public const KIND_HABIT = 'habit';

    public const KIND_ANTI_HABIT = 'anti_habit';

    public const KINDS = [self::KIND_HABIT, self::KIND_ANTI_HABIT];

    public const MODE_YES_NO = 'yes_no';

    public const MODE_NUMERIC = 'numeric';

    public const MODE_ABSTINENCE = 'abstinence';

    public const MODE_STEPPED_LIMIT = 'stepped_limit';

    public const MODES = [
        self::MODE_YES_NO,
        self::MODE_NUMERIC,
        self::MODE_ABSTINENCE,
        self::MODE_STEPPED_LIMIT,
    ];

    protected $fillable = [
        'user_id', 'name', 'description', 'kind', 'mode', 'target_value', 'unit', 'routine_id',
        'goal_id', 'intention_place', 'two_minute_starter', 'is_active', 'is_archived', 'archived_at',
    ];

    protected $attributes = [
        'is_active' => true,
        'is_archived' => false,
    ];

    protected static function booted(): void
    {
        static::saving(function (Habit $habit): void {
            $habit->assertModeConfiguration();
            $habit->assertContextOwnership();

            if (! $habit->exists) {
                return;
            }

            if ($habit->isDirty(['kind', 'mode'])) {
                throw new RuntimeException('A habit kind and mode cannot change after creation.');
            }

            if ($habit->isDirty(['target_value', 'unit']) && $habit->logs()->exists()) {
                throw new RuntimeException('A numeric target and unit cannot change after the first fact.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'target_value' => 'decimal:3',
            'is_active' => 'boolean',
            'is_archived' => 'boolean',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(HabitLog::class);
    }

    public function limitSteps(): HasMany
    {
        return $this->hasMany(HabitLimitStep::class)->orderBy('effective_on')->orderBy('id');
    }

    public function recurringRule(): HasOne
    {
        return $this->hasOne(RecurringRule::class, 'owner_id')
            ->where('owner_type', RecurringRule::OWNER_HABIT);
    }

    /** @param array<string, mixed> $attributes */
    public function applyLifecycle(array $attributes): void
    {
        $wasArchived = $this->is_archived;
        $this->fill($attributes);

        if ($this->is_archived) {
            if (! $wasArchived || $this->archived_at === null) {
                $this->archived_at = now();
            }
        } else {
            $this->archived_at = null;
        }
    }

    public function acceptsOutcome(string $outcome): bool
    {
        if ($outcome === HabitLog::OUTCOME_SKIPPED) {
            return true;
        }

        return match ($this->mode) {
            self::MODE_YES_NO => in_array($outcome, [HabitLog::OUTCOME_DONE, HabitLog::OUTCOME_NOT_DONE], true),
            self::MODE_NUMERIC, self::MODE_STEPPED_LIMIT => $outcome === HabitLog::OUTCOME_RECORDED,
            self::MODE_ABSTINENCE => in_array($outcome, [HabitLog::OUTCOME_PROTECTED, HabitLog::OUTCOME_RELAPSE], true),
            default => false,
        };
    }

    public function logIsSuccessful(HabitLog $log): bool
    {
        return match ($this->mode) {
            self::MODE_YES_NO => $log->outcome === HabitLog::OUTCOME_DONE,
            self::MODE_NUMERIC => $log->outcome === HabitLog::OUTCOME_RECORDED
                && (float) $log->value >= (float) $this->target_value,
            self::MODE_ABSTINENCE => $log->outcome === HabitLog::OUTCOME_PROTECTED,
            self::MODE_STEPPED_LIMIT => $log->outcome === HabitLog::OUTCOME_RECORDED,
            default => false,
        };
    }

    private function assertModeConfiguration(): void
    {
        $valid = match ([$this->kind, $this->mode]) {
            [self::KIND_HABIT, self::MODE_YES_NO] => $this->target_value === null && blank($this->unit),
            [self::KIND_HABIT, self::MODE_NUMERIC] => (float) $this->target_value > 0 && filled($this->unit),
            [self::KIND_ANTI_HABIT, self::MODE_ABSTINENCE] => $this->target_value === null && blank($this->unit),
            [self::KIND_ANTI_HABIT, self::MODE_STEPPED_LIMIT] => $this->target_value === null && filled($this->unit),
            default => false,
        };

        if (! $valid) {
            throw new RuntimeException('The habit kind, mode, target, and unit are incompatible.');
        }
    }

    private function assertContextOwnership(): void
    {
        if (blank($this->user_id)) {
            return;
        }

        if ($this->routine_id !== null) {
            $validRoutine = Routine::query()
                ->whereKey($this->routine_id)
                ->where('user_id', $this->user_id)
                ->where('is_active', true)
                ->where('is_archived', false)
                ->exists();

            if (! $validRoutine) {
                throw new RuntimeException('A habit routine link must name an active owned routine.');
            }
        }

        if ($this->goal_id !== null) {
            $validGoal = Goal::query()
                ->whereKey($this->goal_id)
                ->where('user_id', $this->user_id)
                ->where('status', 'active')
                ->where('is_archived', false)
                ->exists();

            if (! $validGoal) {
                throw new RuntimeException('A habit goal link must name an active owned goal.');
            }
        }
    }
}
