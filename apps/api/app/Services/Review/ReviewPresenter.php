<?php

namespace App\Services\Review;

use App\Models\DailyReview;
use App\Models\PeriodicReview;

class ReviewPresenter
{
    /** @return array<string,mixed> */
    public function daily(DailyReview $review, bool $legacyEnvelope = false): array
    {
        $presented = [
            'id' => $review->id,
            'review_date' => $review->review_date->format('Y-m-d'),
            'mood' => $review->mood,
            'energy' => $review->energy,
            'stress' => $review->stress,
            'day_rating' => $review->day_rating,
            'went_well' => $review->went_well,
            'improve_tomorrow' => $review->improve_tomorrow,
            'notes' => $review->notes,
            'completed_at' => $review->completed_at?->toISOString(),
        ];

        if ($legacyEnvelope) {
            $presented['user_id'] = $review->user_id;
            $presented['created_at'] = $review->created_at?->toISOString();
            $presented['updated_at'] = $review->updated_at?->toISOString();
        }

        return $presented;
    }

    /** @return array<string,mixed> */
    public function periodic(PeriodicReview $review): array
    {
        return [
            'id' => $review->id,
            'period_type' => $review->period_type,
            'period_start' => $review->period_start->format('Y-m-d'),
            'period_end' => $review->period_end->format('Y-m-d'),
            'period_rating' => $review->period_rating,
            'worked_well' => $review->worked_well,
            'did_not_work' => $review->did_not_work,
            'learned' => $review->learned,
            'next_focus' => $review->next_focus,
            'notes' => $review->notes,
            'completed_at' => $review->completed_at->toISOString(),
            'created_at' => $review->created_at->toISOString(),
            'updated_at' => $review->updated_at->toISOString(),
        ];
    }
}
