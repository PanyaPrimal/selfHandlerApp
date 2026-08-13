<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class Exercise extends Model
{
    use HasFactory;

    public const TYPE_STRENGTH = 'strength';

    public const TYPE_MOBILITY = 'mobility';

    protected $fillable = [
        'user_id', 'system_key', 'name', 'muscle_group', 'equipment', 'exercise_type',
        'is_archived', 'archived_at',
    ];

    protected $attributes = ['is_archived' => false];

    protected static function booted(): void
    {
        static::creating(function (Exercise $exercise): void {
            if ($exercise->system_key === null && blank($exercise->user_id)) {
                throw new RuntimeException('A custom exercise requires an owner.');
            }

            if ($exercise->system_key !== null && $exercise->user_id !== null) {
                throw new RuntimeException('A built-in exercise cannot have a private owner.');
            }
        });

        static::updating(function (Exercise $exercise): void {
            if ($exercise->isDirty(['user_id', 'system_key'])) {
                throw new RuntimeException('Exercise ownership and system identity are immutable.');
            }

            if ($exercise->system_key !== null && $exercise->isDirty()) {
                throw new RuntimeException('Built-in exercises are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeVisibleTo(Builder $query, User|int $user): Builder
    {
        $id = $user instanceof User ? $user->id : $user;

        return $query->where(fn (Builder $visible): Builder => $visible
            ->whereNull('user_id')->orWhere('user_id', $id));
    }

    public function isAccessibleTo(User|int $user): bool
    {
        $id = $user instanceof User ? $user->id : $user;

        return $this->user_id === null || (int) $this->user_id === (int) $id;
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
}
