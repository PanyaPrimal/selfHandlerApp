<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class SleepLog extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = [
        'user_id', 'sleep_plan_id', 'sleep_date', 'actual_bed_at', 'actual_wake_at', 'quality', 'note',
    ];

    protected function casts(): array
    {
        return [
            'sleep_date' => 'date:Y-m-d',
            'actual_bed_at' => 'immutable_datetime',
            'actual_wake_at' => 'immutable_datetime',
            'quality' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (SleepLog $log): void {
            if (blank($log->user_id)) {
                return;
            }

            $planOwner = SleepPlan::query()->whereKey($log->sleep_plan_id)->value('user_id');
            if ((int) $planOwner !== (int) $log->user_id) {
                throw new RuntimeException('A sleep log must have the same owner as its plan.');
            }
        });
    }

    public function sleepPlan(): BelongsTo
    {
        return $this->belongsTo(SleepPlan::class);
    }

    public function occurrence(): HasOne
    {
        return $this->hasOne(PlannedOccurrence::class);
    }

    public function durationMinutes(): int
    {
        return (int) $this->actual_bed_at->diffInMinutes($this->actual_wake_at);
    }
}
