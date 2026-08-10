<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Shared ownership boundary for the user-owned domain records.
 *
 * Queries stay visible instead of hiding behind a global scope: a read narrows
 * itself with `ownedBy()`, while a write is refused unless the owner was set on
 * purpose and is never allowed to move to another account.
 */
trait UserOwned
{
    public static function bootUserOwned(): void
    {
        static::creating(function (Model $model): void {
            if (blank($model->getAttribute('user_id'))) {
                throw new RuntimeException($model::class.' requires an owner before it is stored.');
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('user_id')) {
                throw new RuntimeException($model::class.' cannot be moved to another owner.');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOwnedBy(Builder $query, User|int $user): Builder
    {
        return $query->where($query->qualifyColumn('user_id'), $user instanceof User ? $user->getKey() : $user);
    }

    public function isOwnedBy(User|int $user): bool
    {
        return $this->getAttribute('user_id') === ($user instanceof User ? $user->getKey() : $user);
    }
}
