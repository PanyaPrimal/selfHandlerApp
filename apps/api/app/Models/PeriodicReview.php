<?php

namespace App\Models;

use App\Support\UserOwned;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class PeriodicReview extends Model
{
    use HasFactory, UserOwned;

    public const TYPE_WEEKLY = 'weekly';

    public const TYPE_MONTHLY = 'monthly';

    public const TYPES = [self::TYPE_WEEKLY, self::TYPE_MONTHLY];

    protected $fillable = [
        'user_id', 'period_type', 'period_start', 'period_end', 'period_rating', 'worked_well',
        'did_not_work', 'learned', 'next_focus', 'notes', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'period_rating' => 'integer',
            'completed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PeriodicReview $review): void {
            if (! in_array($review->period_type, self::TYPES, true)) {
                throw new RuntimeException('A periodic review requires a supported period type.');
            }

            $start = CarbonImmutable::parse($review->period_start);
            $end = CarbonImmutable::parse($review->period_end);
            $canonical = match ($review->period_type) {
                self::TYPE_WEEKLY => $start->isMonday() && $end->isSameDay($start->addDays(6)),
                self::TYPE_MONTHLY => $start->day === 1 && $end->isSameDay($start->endOfMonth()),
                default => false,
            };

            if (! $canonical) {
                throw new RuntimeException('A periodic review requires canonical calendar boundaries.');
            }
        });
    }
}
