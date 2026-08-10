<?php

namespace Tests\Feature\CoreDailyLoop;

use Carbon\CarbonImmutable;

class ProgressApiTest extends CoreDailyLoopTestCase
{
    private const END_DATE = '2026-08-10';

    public function test_today_returns_owned_daily_and_seven_day_progress_with_exact_streaks(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');

        try {
            config(['selfhandler.timezone' => 'UTC']);
            $owner = $this->createUser();
            $other = $this->createUser('other@example.test', 'Other Owner');

            $currentPending = $this->createRoutine($owner, [
                'name' => 'Current pending',
                'sort_order' => 0,
                'starts_on' => '2026-08-04',
            ]);
            $pastMissing = $this->createRoutine($owner, [
                'name' => 'Past missing',
                'sort_order' => 1,
                'starts_on' => '2026-08-04',
            ], ['MO', 'WE', 'FR']);
            $skippedBreak = $this->createRoutine($owner, [
                'name' => 'Skipped break',
                'sort_order' => 2,
                'starts_on' => '2026-08-04',
            ], ['MO', 'WE', 'FR']);

            $this->createLog($currentPending, '2026-08-04');
            $this->createLog($currentPending, '2026-08-05');
            $this->createLog($currentPending, '2026-08-06', 'skipped');
            $this->createLog($currentPending, '2026-08-07');
            $this->createLog($currentPending, '2026-08-08');
            $this->createLog($currentPending, '2026-08-09');

            $this->createLog($pastMissing, '2026-08-05');
            $this->createLog($pastMissing, self::END_DATE);

            $this->createLog($skippedBreak, '2026-08-05');
            $this->createLog($skippedBreak, '2026-08-07', 'skipped');

            $foreignRoutine = $this->createRoutine($other, [
                'name' => 'Foreign routine',
                'starts_on' => '2026-08-04',
            ]);

            foreach (range(4, 10) as $day) {
                $this->createLog($foreignRoutine, sprintf('2026-08-%02d', $day));
            }

            $response = $this->actingAs($owner)
                ->getJson('/api/today?date='.self::END_DATE)
                ->assertOk()
                ->assertJsonPath('date', self::END_DATE)
                ->assertJsonPath('summary', [
                    'scheduled' => 3,
                    'done' => 1,
                    'skipped' => 0,
                    'pending' => 2,
                    'completion_rate' => 33.33,
                ])
                ->assertJsonPath('progress.period_start', '2026-08-04')
                ->assertJsonPath('progress.period_end', self::END_DATE)
                ->assertJsonPath('progress.seven_day', [
                    'scheduled' => 13,
                    'done' => 8,
                    'skipped' => 2,
                    'pending' => 3,
                    'completion_rate' => 61.54,
                ])
                ->assertJsonPath('routines.0.id', $currentPending->id)
                ->assertJsonPath('routines.0.current_streak', 3)
                ->assertJsonPath('routines.1.id', $pastMissing->id)
                ->assertJsonPath('routines.1.current_streak', 1)
                ->assertJsonPath('routines.2.id', $skippedBreak->id)
                ->assertJsonPath('routines.2.current_streak', 0)
                ->assertJsonStructure([
                    'date',
                    'summary' => ['scheduled', 'done', 'skipped', 'pending', 'completion_rate'],
                    'routines' => [[
                        'id', 'name', 'description', 'kind', 'preferred_time', 'sort_order',
                        'is_active', 'is_archived', 'log', 'goals', 'current_streak',
                    ]],
                    'goals',
                    'review',
                    'progress' => [
                        'period_start',
                        'period_end',
                        'seven_day' => ['scheduled', 'done', 'skipped', 'pending', 'completion_rate'],
                    ],
                ]);

            $this->assertSame(
                [$currentPending->id, $pastMissing->id, $skippedBreak->id],
                collect($response->json('routines'))->pluck('id')->all(),
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_no_scheduled_occurrences_returns_a_deliberate_zero_progress_summary(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');

        try {
            config(['selfhandler.timezone' => 'UTC']);
            $owner = $this->createUser();
            $other = $this->createUser('other@example.test', 'Other Owner');

            $this->createRoutine($owner, [
                'name' => 'Starts after the window',
                'starts_on' => '2026-08-11',
            ]);
            $foreignRoutine = $this->createRoutine($other, [
                'name' => 'Foreign scheduled routine',
                'starts_on' => '2026-08-04',
            ]);
            $this->createLog($foreignRoutine, self::END_DATE);

            $this->actingAs($owner)
                ->getJson('/api/today?date='.self::END_DATE)
                ->assertOk()
                ->assertJsonCount(0, 'routines')
                ->assertJsonPath('summary', [
                    'scheduled' => 0,
                    'done' => 0,
                    'skipped' => 0,
                    'pending' => 0,
                    'completion_rate' => 0,
                ])
                ->assertJsonPath('progress.period_start', '2026-08-04')
                ->assertJsonPath('progress.period_end', self::END_DATE)
                ->assertJsonPath('progress.seven_day', [
                    'scheduled' => 0,
                    'done' => 0,
                    'skipped' => 0,
                    'pending' => 0,
                    'completion_rate' => 0,
                ]);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_streak_uses_the_configured_timezone_when_deciding_that_a_missing_day_ended(): void
    {
        $originalTimezone = config('selfhandler.timezone');
        CarbonImmutable::setTestNow('2026-08-09 21:30:00 UTC');

        try {
            config(['selfhandler.timezone' => 'Europe/Kyiv']);
            $owner = $this->createUser();
            $routine = $this->createRoutine($owner, [
                'name' => 'Timezone boundary',
                'starts_on' => '2026-08-04',
            ]);
            $this->createLog($routine, '2026-08-08');

            $this->actingAs($owner)
                ->getJson('/api/today')
                ->assertOk()
                ->assertJsonPath('date', self::END_DATE)
                ->assertJsonPath('routines.0.id', $routine->id)
                ->assertJsonPath('routines.0.current_streak', 0)
                ->assertJsonPath('progress.period_start', '2026-08-04')
                ->assertJsonPath('progress.period_end', self::END_DATE)
                ->assertJsonPath('progress.seven_day', [
                    'scheduled' => 7,
                    'done' => 1,
                    'skipped' => 0,
                    'pending' => 6,
                    'completion_rate' => 14.29,
                ]);
        } finally {
            config(['selfhandler.timezone' => $originalTimezone]);
            CarbonImmutable::setTestNow();
        }
    }
}
