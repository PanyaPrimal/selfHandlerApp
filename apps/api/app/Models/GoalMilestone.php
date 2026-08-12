<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * An intermediate target on a goal.
 *
 * Achievement is not stored. It is derived from the owning module's history when
 * the goal is read, so a milestone cannot claim something the observations do
 * not support.
 */
class GoalMilestone extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = ['user_id', 'goal_id', 'target_value', 'target_date'];

    protected function casts(): array
    {
        return [
            'target_value' => 'decimal:4',
            'target_date' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (GoalMilestone $milestone): void {
            if (blank($milestone->user_id)) {
                return;
            }

            $goalOwnerId = Goal::withTrashed()->whereKey($milestone->goal_id)->value('user_id');

            if ((int) $goalOwnerId !== (int) $milestone->user_id) {
                throw new RuntimeException('A milestone must have the same owner as its goal.');
            }
        });
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }
}
