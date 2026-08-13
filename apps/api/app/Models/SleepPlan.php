<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SleepPlan extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = [
        'user_id', 'name', 'planned_wake_time', 'is_active', 'is_archived', 'archived_at',
    ];

    protected $attributes = [
        'is_active' => true,
        'is_archived' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function recurringRule(): HasOne
    {
        return $this->hasOne(RecurringRule::class, 'owner_id')
            ->where('owner_type', RecurringRule::OWNER_SLEEP_PLAN);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SleepLog::class);
    }
}
