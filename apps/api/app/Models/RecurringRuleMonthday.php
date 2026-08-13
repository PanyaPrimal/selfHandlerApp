<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class RecurringRuleMonthday extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = ['user_id', 'recurring_rule_id', 'monthday'];

    protected static function booted(): void
    {
        static::saving(function (RecurringRuleMonthday $monthday): void {
            $ownerId = RecurringRule::query()->whereKey($monthday->recurring_rule_id)->value('user_id');
            if ((int) $ownerId !== (int) $monthday->user_id
                || (int) $monthday->monthday < 1 || (int) $monthday->monthday > 31) {
                throw new RuntimeException('A month-day must belong to its owner and be between 1 and 31.');
            }
        });
    }

    protected function casts(): array
    {
        return ['monthday' => 'integer'];
    }

    public function recurringRule(): BelongsTo
    {
        return $this->belongsTo(RecurringRule::class);
    }
}
