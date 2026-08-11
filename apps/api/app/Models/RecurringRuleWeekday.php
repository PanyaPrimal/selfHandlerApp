<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/** One selected weekday of a weekly recurrence rule. */
class RecurringRuleWeekday extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = ['user_id', 'recurring_rule_id', 'weekday'];

    protected static function booted(): void
    {
        static::saving(function (RecurringRuleWeekday $weekday): void {
            if (blank($weekday->user_id)) {
                return;
            }

            $ruleOwnerId = RecurringRule::query()
                ->whereKey($weekday->recurring_rule_id)
                ->value('user_id');

            if ((int) $ruleOwnerId !== (int) $weekday->user_id) {
                throw new RuntimeException('A rule weekday must have the same owner as its rule.');
            }
        });
    }

    public function recurringRule(): BelongsTo
    {
        return $this->belongsTo(RecurringRule::class);
    }
}
