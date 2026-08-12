<?php

namespace App\Models;

use App\Support\UserOwned;
use App\ValueObjects\BodyMetric;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * The body-specific detail of an existing goal.
 *
 * The goal keeps its name, description, status, lifecycle, target date and
 * archive behaviour; only what is specific to a body-composition target lives
 * here. There is no second goal system.
 */
class BodyGoalDetail extends Model
{
    use HasFactory, UserOwned;

    public const DIRECTIONS = ['lose', 'gain', 'maintain'];

    protected $fillable = [
        'user_id',
        'goal_id',
        'metric',
        'direction',
        'starting_value',
        'target_value',
    ];

    protected function casts(): array
    {
        return [
            'metric' => BodyMetric::class,
            'starting_value' => 'decimal:4',
            'target_value' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (BodyGoalDetail $detail): void {
            if (blank($detail->user_id)) {
                return;
            }

            $goalOwnerId = Goal::withTrashed()->whereKey($detail->goal_id)->value('user_id');

            if ((int) $goalOwnerId !== (int) $detail->user_id) {
                throw new RuntimeException('A body goal detail must have the same owner as its goal.');
            }
        });
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }
}
