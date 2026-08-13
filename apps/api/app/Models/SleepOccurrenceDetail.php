<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SleepOccurrenceDetail extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = ['user_id', 'planned_occurrence_id', 'planned_wake_time'];

    protected static function booted(): void
    {
        static::saving(function (SleepOccurrenceDetail $detail): void {
            if (blank($detail->user_id)) {
                return;
            }

            $occurrence = PlannedOccurrence::query()
                ->with('recurringRule:id,user_id,owner_type,owner_id')
                ->find($detail->planned_occurrence_id);

            if (! $occurrence
                || (int) $occurrence->user_id !== (int) $detail->user_id
                || $occurrence->recurringRule?->owner_type !== RecurringRule::OWNER_SLEEP_PLAN) {
                throw new RuntimeException('A sleep detail must share a sleep occurrence owner.');
            }
        });
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(PlannedOccurrence::class, 'planned_occurrence_id');
    }
}
