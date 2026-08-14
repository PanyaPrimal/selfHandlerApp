<?php

namespace App\Services\Review;

use App\Models\PeriodicReview;
use App\Models\User;
use App\Support\ReviewPeriod;
use Illuminate\Support\Facades\DB;

class PeriodicReviewWriter
{
    /** @param array<string, int|string|null> $data */
    public function upsert(User $user, ReviewPeriod $period, array $data): PeriodicReview
    {
        return DB::transaction(function () use ($user, $period, $data): PeriodicReview {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $identity = [
                'user_id' => $user->id,
                'period_type' => $period->type,
                'period_start' => $period->start,
            ];
            PeriodicReview::query()->upsert([[
                ...$identity,
                'period_end' => $period->end,
                ...$data,
                'completed_at' => now(),
            ]], ['user_id', 'period_type', 'period_start'], ['period_end', ...array_keys($data)]);

            return PeriodicReview::query()->where($identity)->firstOrFail();
        }, attempts: 3);
    }
}
