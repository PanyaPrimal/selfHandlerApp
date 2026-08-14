<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\AnalyticsCatalog;
use PHPUnit\Framework\TestCase;

class AnalyticsCatalogTest extends TestCase
{
    public function test_catalog_is_closed_unique_and_matches_delivery_contract(): void
    {
        $catalog = new AnalyticsCatalog;
        $metrics = $catalog->metrics();
        $correlations = $catalog->correlations();

        $this->assertCount(17, $metrics);
        $this->assertSame(array_column($metrics, 'key'), array_values(array_unique(array_column($metrics, 'key'))));
        $this->assertSame([
            'routines.completion_rate', 'sleep.duration_minutes', 'sleep.quality',
            'workouts.completed_sessions', 'workouts.duration_minutes',
            'nutrition.calorie_target_adherence', 'supplements.adherence', 'habits.completion_rate',
            'planner.completion_rate', 'finance.income', 'finance.expense', 'finance.net',
            'review.energy', 'review.mood', 'review.stress', 'review.day_rating', 'body.body_mass',
        ], array_column($metrics, 'key'));
        $this->assertCount(3, $correlations);
        $this->assertSame(['daily_days' => 93, 'weekly_days' => 730, 'monthly_days' => 3653,
            'correlation_days' => 366], $catalog->limits());
    }
}
