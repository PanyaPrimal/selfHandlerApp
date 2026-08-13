<?php

namespace Tests\Unit\Recurrence;

use App\Models\RecurringRule;
use App\Models\User;
use App\Services\RecurringRuleExpander;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyRecurrenceRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_and_weekly_interval_sets_remain_exact(): void
    {
        $owner = User::factory()->create();
        $daily = RecurringRule::query()->create([
            'user_id' => $owner->id, 'owner_type' => 'legacy-test-daily', 'owner_id' => 900001,
            'frequency' => RecurringRule::FREQUENCY_DAILY, 'interval_count' => 2,
            'starts_on' => '2026-08-01', 'ends_on' => '2026-08-10', 'timezone' => 'Europe/Kyiv',
        ]);
        $weekly = RecurringRule::query()->create([
            'user_id' => $owner->id, 'owner_type' => 'legacy-test-weekly', 'owner_id' => 900002,
            'frequency' => RecurringRule::FREQUENCY_WEEKLY, 'interval_count' => 2,
            'starts_on' => '2026-08-03', 'ends_on' => '2026-08-31', 'timezone' => 'Europe/Kyiv',
        ]);
        $weekly->syncWeekdays(['MO', 'WE']);
        $expander = app(RecurringRuleExpander::class);

        $this->assertSame(
            ['2026-08-01', '2026-08-03', '2026-08-05', '2026-08-07', '2026-08-09'],
            $expander->datesBetween($daily->fresh(), '2026-08-01', '2026-08-10'),
        );
        $this->assertSame(
            ['2026-08-03', '2026-08-05', '2026-08-17', '2026-08-19', '2026-08-31'],
            $expander->datesBetween($weekly->fresh(), '2026-08-01', '2026-08-31'),
        );
    }
}
