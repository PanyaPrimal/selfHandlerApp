<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class SupplementIntake extends Model
{
    use HasFactory, UserOwned;

    public const OUTCOME_TAKEN = 'taken';

    public const OUTCOME_SKIPPED = 'skipped';

    public const OUTCOMES = [self::OUTCOME_TAKEN, self::OUTCOME_SKIPPED];

    protected $fillable = [
        'user_id', 'supplement_course_id', 'supplement_id', 'planned_on', 'effective_on', 'slot',
        'outcome', 'dose_quantity', 'dose_display_unit', 'supplement_name', 'taken_at', 'note',
    ];

    protected static function booted(): void
    {
        static::saving(function (SupplementIntake $intake): void {
            if (blank($intake->user_id)) {
                return;
            }

            $course = SupplementCourse::query()
                ->whereKey($intake->supplement_course_id)
                ->where('user_id', $intake->user_id)
                ->where('supplement_id', $intake->supplement_id)
                ->exists();
            if (! $course) {
                throw new RuntimeException('An intake must match its owned course and supplement.');
            }

            if ($intake->outcome === self::OUTCOME_TAKEN && $intake->taken_at === null) {
                throw new RuntimeException('A taken intake requires an actual instant.');
            }
            if ($intake->outcome === self::OUTCOME_SKIPPED && $intake->taken_at !== null) {
                throw new RuntimeException('A skipped intake cannot have an actual instant.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'planned_on' => 'date:Y-m-d',
            'effective_on' => 'date:Y-m-d',
            'dose_quantity' => 'decimal:6',
            'taken_at' => 'immutable_datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(SupplementCourse::class, 'supplement_course_id');
    }

    public function supplement(): BelongsTo
    {
        return $this->belongsTo(Supplement::class);
    }

    public function plannedOccurrence(): HasOne
    {
        return $this->hasOne(PlannedOccurrence::class);
    }
}
