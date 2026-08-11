<?php

namespace Tests\Unit\CoreDailyLoop;

use App\Services\RoutineProgressService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CoreDailyLoop\CoreDailyLoopTestCase;

class RoutineProgressServiceTest extends CoreDailyLoopTestCase
{
    private const MONDAY = '2026-08-10';

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_progress_uses_the_users_timezone_instead_of_the_installation_fallback(): void
    {
        config(['selfhandler.timezone' => 'America/New_York']);
        $owner = $this->createUser();
        $owner->ensureProfile()->update(['timezone' => 'Europe/Kyiv']);
        $this->createRoutine($owner, [
            'starts_on' => self::MONDAY,
            'is_archived' => true,
            'archived_at' => '2026-08-10 22:30:00 UTC',
        ]);

        $progress = $this->service()->calculate($owner, self::MONDAY);

        $this->assertSame(1, $progress['seven_day']['scheduled']);
    }

    public function test_mixed_daily_and_weekday_history_has_exact_seven_day_counts(): void
    {
        $service = $this->service();
        CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');
        $owner = $this->createUser();
        $daily = $this->createRoutine($owner, ['name' => 'Daily routine']);
        $weekdays = $this->createRoutine($owner, ['name' => 'Weekday routine'], ['MO', 'WE', 'FR']);

        foreach (['2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07'] as $date) {
            $this->createLog($daily, $date);
        }
        $this->createLog($daily, '2026-08-08', 'skipped');
        $this->createLog($weekdays, '2026-08-05');
        $this->createLog($weekdays, '2026-08-07', 'skipped');

        $progress = $service->calculate($owner, self::MONDAY);

        $this->assertSame('2026-08-04', $progress['period_start']);
        $this->assertSame(self::MONDAY, $progress['period_end']);
        $this->assertSummary($progress['seven_day'], 10, 5, 2, 3, 50.0);
        $this->assertSame(0, $progress['routine_streaks'][$daily->id]);
        $this->assertSame(0, $progress['routine_streaks'][$weekdays->id]);
    }

    public function test_streak_walks_consecutive_scheduled_occurrences_not_calendar_days(): void
    {
        $service = $this->service();
        CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner, ['name' => 'Training'], ['MO', 'WE', 'FR']);

        $this->createLog($routine, '2026-07-31', 'skipped');
        foreach (['2026-08-03', '2026-08-05', '2026-08-07'] as $date) {
            $this->createLog($routine, $date);
        }

        $progress = $service->calculate($owner, self::MONDAY);

        $this->assertSame(3, $progress['routine_streaks'][$routine->id]);
    }

    public function test_skipped_scheduled_occurrence_breaks_the_streak(): void
    {
        $service = $this->service();
        CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);

        $this->createLog($routine, '2026-08-07');
        $this->createLog($routine, '2026-08-08', 'skipped');
        $this->createLog($routine, '2026-08-09');
        $this->createLog($routine, self::MONDAY);

        $progress = $service->calculate($owner, self::MONDAY);

        $this->assertSame(2, $progress['routine_streaks'][$routine->id]);
    }

    public function test_missing_ended_occurrence_breaks_the_streak(): void
    {
        $service = $this->service();
        CarbonImmutable::setTestNow('2026-08-11 00:00:01 UTC');
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);

        $this->createLog($routine, '2026-08-08');
        $this->createLog($routine, '2026-08-09');

        $progress = $service->calculate($owner, self::MONDAY);

        $this->assertSame(0, $progress['routine_streaks'][$routine->id]);
    }

    public function test_current_and_future_pending_occurrences_do_not_break_the_streak(): void
    {
        $service = $this->service();
        CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);

        $this->createLog($routine, '2026-08-08');
        $this->createLog($routine, '2026-08-09');

        $currentProgress = $service->calculate($owner, self::MONDAY);
        $futureProgress = $service->calculate($owner, '2026-08-12');

        $this->assertSame(2, $currentProgress['routine_streaks'][$routine->id]);
        $this->assertSame(2, $futureProgress['routine_streaks'][$routine->id]);
    }

    public function test_validity_dates_and_weekdays_exclude_unscheduled_logs(): void
    {
        $service = $this->service();
        CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');
        $owner = $this->createUser();
        $bounded = $this->createRoutine($owner, [
            'name' => 'Bounded routine',
            'starts_on' => '2026-08-06',
            'ends_on' => '2026-08-08',
        ]);
        $mondayOnly = $this->createRoutine($owner, ['name' => 'Monday only'], ['MO']);

        $this->createLog($bounded, '2026-08-05');
        $this->createLog($bounded, '2026-08-06');
        $this->createLog($bounded, '2026-08-07', 'skipped');
        $this->createLog($bounded, '2026-08-09');
        $this->createLog($mondayOnly, '2026-08-09');

        $progress = $service->calculate($owner, self::MONDAY);

        $this->assertSummary($progress['seven_day'], 4, 1, 1, 2, 25.0);
    }

    public function test_empty_period_returns_zero_counts_and_zero_rate(): void
    {
        $service = $this->service();
        CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');
        $owner = $this->createUser();

        $progress = $service->calculate($owner, self::MONDAY);

        $this->assertSame('2026-08-04', $progress['period_start']);
        $this->assertSame(self::MONDAY, $progress['period_end']);
        $this->assertSummary($progress['seven_day'], 0, 0, 0, 0, 0.0);
        $this->assertSame([], $progress['routine_streaks']);
    }

    public function test_selected_instant_uses_the_configured_calendar_timezone(): void
    {
        $service = $this->service();
        config(['selfhandler.timezone' => 'Europe/Kyiv']);
        CarbonImmutable::setTestNow('2026-08-09 21:30:00 UTC');
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner, ['name' => 'Monday routine'], ['MO']);
        $this->createLog($routine, self::MONDAY);
        $selectedInstant = CarbonImmutable::parse('2026-08-09 21:30:00 UTC');

        $progress = $service->calculate($owner, $selectedInstant);

        $this->assertSame('2026-08-04', $progress['period_start']);
        $this->assertSame(self::MONDAY, $progress['period_end']);
        $this->assertSummary($progress['seven_day'], 1, 1, 0, 0, 100.0);
        $this->assertSame(1, $progress['routine_streaks'][$routine->id]);
    }

    public function test_archived_routine_keeps_only_pre_archive_history(): void
    {
        $service = $this->service();
        config(['selfhandler.timezone' => 'Europe/Kyiv']);
        CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner, [
            'name' => 'Archived routine',
            'starts_on' => '2026-08-04',
            'is_archived' => true,
            'archived_at' => '2026-08-08 21:30:00',
        ]);

        foreach (['2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07', '2026-08-08'] as $date) {
            $this->createLog($routine, $date);
        }
        $this->createLog($routine, '2026-08-09');

        $progress = $service->calculate($owner, self::MONDAY);

        $this->assertSummary($progress['seven_day'], 5, 5, 0, 0, 100.0);
        $this->assertSame(5, $progress['routine_streaks'][$routine->id]);
    }

    public function test_large_history_uses_a_fixed_query_budget_without_n_plus_one_queries(): void
    {
        $service = $this->service();
        CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');
        $owner = $this->createUser();
        $selectedDate = CarbonImmutable::parse(self::MONDAY, 'UTC');
        $historyStart = $selectedDate->subDays(364);
        $timestamp = '2026-08-10 12:00:00';
        $routineIds = range(1, 500);

        DB::transaction(function () use ($historyStart, $owner, $routineIds, $selectedDate, $timestamp): void {
            $routineRows = array_map(static fn (int $routineId): array => [
                'id' => $routineId,
                'user_id' => $owner->id,
                'name' => 'Routine '.$routineId,
                'schedule_type' => 'daily',
                'starts_on' => $historyStart->toDateString(),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ], $routineIds);
            DB::table('routines')->insert($routineRows);

            for ($date = $historyStart; $date->lessThanOrEqualTo($selectedDate); $date = $date->addDay()) {
                $dateValue = $date->toDateString();
                $logRows = array_map(static fn (int $routineId): array => [
                    'user_id' => $owner->id,
                    'routine_id' => $routineId,
                    'log_date' => $dateValue,
                    'status' => 'done',
                    'completed_at' => $dateValue.' 08:00:00',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ], $routineIds);
                DB::table('routine_logs')->insert($logRows);
            }
        });

        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $progress = $service->calculate($owner, $selectedDate);
            $queryCount = count($connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
        }

        $this->assertLessThanOrEqual(
            5,
            $queryCount,
            'Progress calculation must load routines, weekday rows, and history in a fixed number of queries.',
        );
        $this->assertSummary($progress['seven_day'], 3500, 3500, 0, 0, 100.0);
        $this->assertCount(500, $progress['routine_streaks']);
        $this->assertSame(365, $progress['routine_streaks'][1]);
        $this->assertSame(365, $progress['routine_streaks'][500]);
    }

    private function service(): RoutineProgressService
    {
        return app(RoutineProgressService::class);
    }

    /**
     * @param  array<string, int|float>  $summary
     */
    private function assertSummary(
        array $summary,
        int $scheduled,
        int $done,
        int $skipped,
        int $pending,
        float $completionRate,
    ): void {
        $this->assertSame($scheduled, $summary['scheduled']);
        $this->assertSame($done, $summary['done']);
        $this->assertSame($skipped, $summary['skipped']);
        $this->assertSame($pending, $summary['pending']);
        $this->assertEqualsWithDelta($completionRate, (float) $summary['completion_rate'], 0.001);
    }
}
