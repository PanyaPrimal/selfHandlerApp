<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class RecurringRuleSlot extends Model
{
    use HasFactory, UserOwned;

    protected $fillable = [
        'user_id', 'recurring_rule_id', 'slot', 'occurrence_time', 'sort_order',
    ];

    protected static function booted(): void
    {
        static::saving(function (RecurringRuleSlot $slot): void {
            if (blank($slot->user_id)) {
                return;
            }

            $owner = RecurringRule::query()->whereKey($slot->recurring_rule_id)->value('user_id');
            if ((int) $owner !== (int) $slot->user_id) {
                throw new RuntimeException('A recurrence slot must have the same owner as its rule.');
            }
        });
    }

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function recurringRule(): BelongsTo
    {
        return $this->belongsTo(RecurringRule::class);
    }

    public function supplementDetail(): HasOne
    {
        return $this->hasOne(SupplementCourseSlot::class);
    }
}
