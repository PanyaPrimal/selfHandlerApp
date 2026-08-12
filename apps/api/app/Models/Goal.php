<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Goal extends Model
{
    use HasFactory, SoftDeletes, UserOwned;

    public const TYPE_GENERAL = 'general';

    /** A body-composition goal: the same goal, with a typed detail attached. */
    public const TYPE_BODY = 'body';

    protected $attributes = [
        'type' => 'general',
        'status' => 'active',
        'is_archived' => false,
    ];

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'type',
        'status',
        'target_date',
        'completed_at',
        'is_archived',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date:Y-m-d',
            'completed_at' => 'datetime',
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function bodyDetail(): HasOne
    {
        return $this->hasOne(BodyGoalDetail::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(GoalMilestone::class);
    }

    public function routines(): BelongsToMany
    {
        return $this->belongsToMany(Routine::class)
            ->withPivot('user_id')
            ->withTimestamps();
    }

    /**
     * Apply editable fields and derive their lifecycle timestamps together.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function applyLifecycle(array $attributes): void
    {
        $wasCompleted = $this->status === 'completed';
        $wasArchived = $this->is_archived;

        $this->fill($attributes);

        if ($this->status === 'completed') {
            if (! $wasCompleted || $this->completed_at === null) {
                $this->completed_at = now();
            }
        } else {
            $this->completed_at = null;
        }

        if ($this->is_archived) {
            if (! $wasArchived || $this->archived_at === null) {
                $this->archived_at = now();
            }
        } else {
            $this->archived_at = null;
        }
    }
}
