<?php

namespace Tests\Unit\SleepRoutineTemplates;

use App\Services\SleepLogService;
use App\Services\SleepStatisticsService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\SleepRoutineTemplates\SleepRoutineTestCase;

class SleepStatisticsServiceTest extends SleepRoutineTestCase
{
    /** @return array<string, mixed> */
    private function log(string $bedDate, string $wakeDate, string $wakeTime, int $quality): array
    {
        return [
            'actual_bed_date' => $bedDate,
            'actual_bed_time' => '23:00',
            'actual_wake_date' => $wakeDate,
            'actual_wake_time' => $wakeTime,
            'quality' => $quality,
            'note' => null,
        ];
    }

    public function test_empty_range_is_honest_and_selected_night_keeps_planned_context(): void
    {
        $owner = $this->createUser();
        $plan = $this->createSleepPlan($owner);

        $summary = app(SleepStatisticsService::class)->summarize(
            $owner,
            self::TODAY,
            self::TODAY,
            self::TODAY,
        );

        $this->assertSame(1, $summary['planned_nights']);
        $this->assertSame(0, $summary['recorded_nights']);
        $this->assertNull($summary['average_duration_minutes']);
        $this->assertNull($summary['average_quality']);
        $this->assertSame($plan->id, $summary['selected_night']['sleep_plan_id']);
        $this->assertSame('planned', $summary['selected_night']['state']);
        $this->assertNull($summary['selected_night']['log']);
    }

    public function test_inclusive_range_averages_source_facts_and_reflects_corrections(): void
    {
        $owner = $this->createUser();
        $plan = $this->createSleepPlan($owner);
        $logs = app(SleepLogService::class);
        $logs->upsert($plan, $owner, self::TODAY, $this->log(self::TODAY, self::TOMORROW, '07:00', 8));
        $logs->upsert($plan, $owner, self::TOMORROW, $this->log(self::TOMORROW, '2026-08-15', '05:00', 6));

        $before = app(SleepStatisticsService::class)->summarize(
            $owner,
            self::TODAY,
            self::TOMORROW,
            self::TODAY,
        );
        $this->assertSame(2, $before['planned_nights']);
        $this->assertSame(2, $before['recorded_nights']);
        $this->assertSame(420.0, $before['average_duration_minutes']);
        $this->assertSame(7.0, $before['average_quality']);

        $logs->upsert($plan, $owner, self::TOMORROW, $this->log(self::TOMORROW, '2026-08-15', '07:00', 10));
        $after = app(SleepStatisticsService::class)->summarize(
            $owner,
            self::TODAY,
            self::TOMORROW,
            self::TODAY,
        );
        $this->assertSame(480.0, $after['average_duration_minutes']);
        $this->assertSame(9.0, $after['average_quality']);
    }

    public function test_statistics_are_owner_and_profile_timezone_scoped(): void
    {
        $owner = $this->createUser(timezone: 'Europe/Kyiv');
        $other = $this->createUser('other@example.test', 'America/New_York');
        $plan = $this->createSleepPlan($owner);
        $otherPlan = $this->createSleepPlan($other);
        app(SleepLogService::class)->upsert($plan, $owner, self::TODAY, $this->log(self::TODAY, self::TOMORROW, '07:00', 9));
        app(SleepLogService::class)->upsert($otherPlan, $other, self::TODAY, $this->log(self::TODAY, self::TOMORROW, '07:00', 2));

        $summary = app(SleepStatisticsService::class)->summarize($owner, self::TODAY, self::TODAY, self::TODAY);

        $this->assertSame(1, $summary['recorded_nights']);
        $this->assertSame(9.0, $summary['average_quality']);
        $this->assertSame('2026-08-13T20:00:00.000000Z', $summary['selected_night']['log']['actual_bed_at']);
    }

    public function test_range_read_has_a_fixed_query_budget_as_history_grows(): void
    {
        $owner = $this->createUser();
        $this->createSleepPlan($owner);
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(SleepStatisticsService::class)->summarize($owner, self::TODAY, '2026-11-11', self::TODAY);

        $this->assertLessThanOrEqual(8, count(DB::getQueryLog()));
    }
}
