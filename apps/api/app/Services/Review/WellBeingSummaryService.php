<?php

namespace App\Services\Review;

use App\Models\DailyReview;
use App\Models\User;
use Carbon\CarbonImmutable;

class WellBeingSummaryService
{
    /** @return array<string,int|float|null> */
    public function summarize(User $user, string $from, string $to): array
    {
        $row = DailyReview::query()->ownedBy($user)->whereBetween('review_date', [$from, $to])
            ->selectRaw('COUNT(*) as reviewed_days, AVG(mood) as mood, AVG(energy) as energy,
                AVG(stress) as stress, AVG(day_rating) as day_rating')->first();

        return [
            'reviewed_days' => (int) ($row?->reviewed_days ?? 0),
            'period_days' => CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1,
            'mood' => $this->average($row?->mood),
            'energy' => $this->average($row?->energy),
            'stress' => $this->average($row?->stress),
            'day_rating' => $this->average($row?->day_rating),
        ];
    }

    private function average(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }
}
