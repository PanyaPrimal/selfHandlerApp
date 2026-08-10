<?php

namespace Tests\Feature\CoreDailyLoop;

/**
 * Calendar dates are days, not instants.
 *
 * They must reach the client as `YYYY-MM-DD` exactly as the OpenAPI contract
 * declares, including when the framework runs on a timezone ahead of UTC, where
 * an instant-shaped value would report the previous day.
 */
class CalendarDateContractTest extends CoreDailyLoopTestCase
{
    private const DATE = '2026-08-16';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Europe/Kyiv']);
        date_default_timezone_set('Europe/Kyiv');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set('UTC');

        parent::tearDown();
    }

    public function test_goal_and_routine_calendar_dates_are_day_precise(): void
    {
        $owner = $this->createUser();
        $this->createGoal($owner, ['target_date' => self::DATE]);
        $this->createRoutine($owner, ['starts_on' => self::DATE, 'ends_on' => '2026-09-01']);

        $this->actingAs($owner);

        $this->getJson('/api/goals')
            ->assertOk()
            ->assertJsonPath('data.0.target_date', self::DATE);

        $this->getJson('/api/routines')
            ->assertOk()
            ->assertJsonPath('data.0.starts_on', self::DATE)
            ->assertJsonPath('data.0.ends_on', '2026-09-01');
    }

    public function test_log_and_review_calendar_dates_are_day_precise(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);

        $this->actingAs($owner);

        $this->putJson("/api/routines/{$routine->id}/logs/".self::DATE, ['status' => 'done'])
            ->assertOk()
            ->assertJsonPath('data.log_date', self::DATE);

        $this->putJson('/api/daily-reviews/'.self::DATE, ['mood' => 7])
            ->assertOk()
            ->assertJsonPath('data.review_date', self::DATE);

        $this->getJson('/api/today?date='.self::DATE)
            ->assertOk()
            ->assertJsonPath('date', self::DATE)
            ->assertJsonPath('routines.0.log.log_date', self::DATE)
            ->assertJsonPath('review.review_date', self::DATE);
    }
}
