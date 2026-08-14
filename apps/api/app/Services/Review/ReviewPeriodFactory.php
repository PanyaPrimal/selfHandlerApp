<?php

namespace App\Services\Review;

use App\Models\PeriodicReview;
use App\Support\ReviewPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class ReviewPeriodFactory
{
    public const TYPE_DAILY = 'daily';

    public function make(string $type, string $anchor, string $timezone): ReviewPeriod
    {
        if (! in_array($type, [self::TYPE_DAILY, ...PeriodicReview::TYPES], true)) {
            throw ValidationException::withMessages(['period' => __('messages.review_period_invalid')]);
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $anchor, $timezone);
        } catch (\Throwable) {
            $date = false;
        }
        if (! $date || $date->format('Y-m-d') !== $anchor) {
            throw ValidationException::withMessages(['anchor' => __('messages.review_date_invalid')]);
        }

        [$start, $end] = match ($type) {
            self::TYPE_DAILY => [$date, $date],
            PeriodicReview::TYPE_WEEKLY => [$date->startOfWeek(), $date->endOfWeek()],
            PeriodicReview::TYPE_MONTHLY => [$date->startOfMonth(), $date->endOfMonth()],
        };

        return new ReviewPeriod(
            type: $type,
            anchor: $anchor,
            start: $start->toDateString(),
            end: $end->toDateString(),
            timezone: $timezone,
        );
    }
}
