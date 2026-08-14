<?php

namespace App\Services\Review;

use App\Models\DailyReview;
use App\Models\PeriodicReview;
use App\Models\User;

class ReviewWorkspaceService
{
    public function __construct(
        private readonly ReviewPeriodFactory $periods,
        private readonly AggregateRegistry $aggregates,
        private readonly DayScoreService $scores,
        private readonly WellBeingSummaryService $wellBeing,
        private readonly ReviewPresenter $presenter,
    ) {}

    /** @return array<string,mixed> */
    public function daily(User $user, string $date, bool $legacyReviewEnvelope = false): array
    {
        $period = $this->periods->make(ReviewPeriodFactory::TYPE_DAILY, $date, $user->calendarTimezone());
        $modules = $this->aggregates->daily($user, $period->start);
        $review = DailyReview::query()->ownedBy($user)->whereDate('review_date', $period->start)->first();

        return [
            'period' => $period->toArray(),
            'review' => $review ? $this->presenter->daily($review, $legacyReviewEnvelope) : null,
            'modules' => $modules,
            'day_score' => $this->scores->compose($modules),
        ];
    }

    /** @return array<string,mixed> */
    public function periodic(User $user, string $type, string $anchor): array
    {
        $period = $this->periods->make($type, $anchor, $user->calendarTimezone());
        $review = PeriodicReview::query()->ownedBy($user)
            ->where('period_type', $period->type)->whereDate('period_start', $period->start)->first();

        return [
            'period' => $period->toArray(),
            'review' => $review ? $this->presenter->periodic($review) : null,
            'modules' => $this->aggregates->period($user, $period->start, $period->end),
            'well_being' => $this->wellBeing->summarize($user, $period->start, $period->end),
        ];
    }
}
