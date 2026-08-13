<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class SupplementCourse extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = [
        'user_id', 'supplement_id', 'goal_id', 'name', 'dose_quantity', 'dose_display_unit',
        'starts_on', 'ends_on', 'is_active', 'is_archived', 'archived_at',
    ];

    protected $attributes = ['is_active' => true, 'is_archived' => false];

    protected static function booted(): void
    {
        static::saving(function (SupplementCourse $course): void {
            if (blank($course->user_id)) {
                return;
            }

            $supplement = Supplement::query()
                ->whereKey($course->supplement_id)
                ->where('user_id', $course->user_id)
                ->first();
            if (! $supplement) {
                throw new RuntimeException('A course supplement must belong to the same owner.');
            }

            if ($course->goal_id !== null && ! Goal::query()
                ->whereKey($course->goal_id)
                ->where('user_id', $course->user_id)
                ->exists()) {
                throw new RuntimeException('A course goal must belong to the same owner.');
            }

            if ($course->exists && $course->isDirty('supplement_id')) {
                throw new RuntimeException('A course cannot move to another supplement.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'dose_quantity' => 'decimal:6',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'is_active' => 'boolean',
            'is_archived' => 'boolean',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function supplement(): BelongsTo
    {
        return $this->belongsTo(Supplement::class);
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function recurringRule(): HasOne
    {
        return $this->hasOne(RecurringRule::class, 'owner_id')
            ->where('owner_type', RecurringRule::OWNER_SUPPLEMENT_COURSE);
    }

    public function courseSlots(): HasMany
    {
        return $this->hasMany(SupplementCourseSlot::class);
    }

    public function intakes(): HasMany
    {
        return $this->hasMany(SupplementIntake::class);
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
