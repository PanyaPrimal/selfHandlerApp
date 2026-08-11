<?php

namespace Tests\Unit\CoreDailyLoop;

use App\Services\RoutineScheduleService;
use Carbon\CarbonImmutable;
use Tests\Feature\CoreDailyLoop\CoreDailyLoopTestCase;

class RoutineScheduleServiceTest extends CoreDailyLoopTestCase
{
    private RoutineScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(RoutineScheduleService::class);
    }

    public function test_daily_schedule_observes_inclusive_start_and_end_dates(): void
    {
        $routine = $this->createRoutine($this->createUser(), [
            'starts_on' => '2026-08-10',
            'ends_on' => '2026-08-12',
        ]);

        $this->assertFalse($this->service->isScheduledFor($routine, '2026-08-09'));
        $this->assertTrue($this->service->isScheduledFor($routine, '2026-08-10'));
        $this->assertTrue($this->service->isScheduledFor($routine, '2026-08-12'));
        $this->assertFalse($this->service->isScheduledFor($routine, '2026-08-13'));
    }

    public function test_weekday_schedule_uses_normalized_weekday_rows(): void
    {
        $routine = $this->createRoutine($this->createUser(), [], ['WE', 'MO']);

        $this->assertTrue($this->service->isScheduledFor($routine, '2026-08-10'));
        $this->assertFalse($this->service->isScheduledFor($routine, '2026-08-11'));
        $this->assertTrue($this->service->isScheduledFor($routine, '2026-08-12'));
    }

    public function test_empty_or_unknown_schedules_are_never_scheduled(): void
    {
        $owner = $this->createUser();
        $emptyWeekdays = $this->createRoutine($owner, ['schedule_type' => 'weekdays']);
        $unknown = $this->createRoutine($owner, ['schedule_type' => 'future_engine_rule']);

        $this->assertFalse($this->service->isScheduledFor($emptyWeekdays, '2026-08-10'));
        $this->assertFalse($this->service->isScheduledFor($unknown, '2026-08-10'));
    }

    public function test_paused_and_soft_deleted_routines_are_not_scheduled(): void
    {
        $owner = $this->createUser();
        $paused = $this->createRoutine($owner, ['is_active' => false]);
        $deleted = $this->createRoutine($owner);
        $deleted->delete();

        $this->assertFalse($this->service->isScheduledFor($paused, '2026-08-10'));
        $this->assertFalse($this->service->isScheduledFor($deleted, '2026-08-10'));
    }

    public function test_archive_boundary_uses_the_configured_calendar_timezone(): void
    {
        config(['selfhandler.timezone' => 'Europe/Kyiv']);
        $routine = $this->createRoutine($this->createUser(), [
            'is_archived' => true,
            'archived_at' => '2026-08-11 21:30:00',
        ]);

        $this->assertTrue($this->service->isScheduledFor($routine, '2026-08-11'));
        $this->assertFalse($this->service->isScheduledFor($routine, '2026-08-12'));
    }

    public function test_an_instant_is_evaluated_as_a_calendar_date_in_the_configured_timezone(): void
    {
        config(['selfhandler.timezone' => 'Europe/Kyiv']);
        $routine = $this->createRoutine($this->createUser(), [], ['MO']);
        $sundayEveningUtc = CarbonImmutable::parse('2026-08-09 21:30:00 UTC');

        $this->assertTrue($this->service->isScheduledFor($routine, $sundayEveningUtc));
    }

    public function test_explicit_user_timezone_overrides_the_installation_fallback(): void
    {
        config(['selfhandler.timezone' => 'America/New_York']);
        $routine = $this->createRoutine($this->createUser(), [], ['MO']);
        $sundayEveningUtc = CarbonImmutable::parse('2026-08-09 21:30:00 UTC');

        $this->assertTrue($this->service->isScheduledFor($routine, $sundayEveningUtc, 'Europe/Kyiv'));
        $this->assertFalse($this->service->isScheduledFor($routine, $sundayEveningUtc, 'America/New_York'));
    }
}
