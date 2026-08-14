<?php

namespace App\Services\Review;

use App\Models\DailyReview;
use App\Models\User;

class ReviewAnalyticsSeriesService
{
    /** @return array<string,list<array<string,mixed>>> */
    public function daily(User $user, string $from, string $to): array
    {
        $result = ['energy' => [], 'mood' => [], 'stress' => [], 'day_rating' => []];
        $reviews = DailyReview::query()->ownedBy($user)->whereBetween('review_date', [$from, $to])
            ->orderBy('review_date')->get(['review_date', ...array_keys($result)]);
        foreach ($reviews as $review) {
            $date = $review->review_date->format('Y-m-d');
            foreach (array_keys($result) as $field) {
                if ($review->{$field} !== null) {
                    $result[$field][] = [
                        'date' => $date, 'numerator' => (string) $review->{$field}, 'denominator' => '1',
                        'sample_count' => 1, 'complete' => true, 'reasons' => [],
                    ];
                }
            }
        }

        return $result;
    }
}
