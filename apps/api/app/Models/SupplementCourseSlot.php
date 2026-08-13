<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SupplementCourseSlot extends Model
{
    use HasFactory, UserOwned;

    public const CONTEXTS = ['unspecified', 'with_food', 'empty_stomach'];

    protected $fillable = [
        'user_id', 'supplement_course_id', 'recurring_rule_slot_id', 'intake_context',
    ];

    protected static function booted(): void
    {
        static::saving(function (SupplementCourseSlot $detail): void {
            if (blank($detail->user_id)) {
                return;
            }

            $course = SupplementCourse::query()
                ->whereKey($detail->supplement_course_id)
                ->where('user_id', $detail->user_id)
                ->first();
            $slot = RecurringRuleSlot::query()
                ->whereKey($detail->recurring_rule_slot_id)
                ->where('user_id', $detail->user_id)
                ->with('recurringRule')
                ->first();
            if (! $course || ! $slot
                || $slot->recurringRule?->owner_type !== RecurringRule::OWNER_SUPPLEMENT_COURSE
                || (int) $slot->recurringRule?->owner_id !== (int) $course->id) {
                throw new RuntimeException('A course slot must belong to the course rule and owner.');
            }
        });
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(SupplementCourse::class, 'supplement_course_id');
    }

    public function ruleSlot(): BelongsTo
    {
        return $this->belongsTo(RecurringRuleSlot::class, 'recurring_rule_slot_id');
    }
}
