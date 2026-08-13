<?php

namespace Tests\Feature\Habits;

use App\Models\Goal;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\PlannedOccurrence;
use App\Models\Routine;
use App\Models\User;
use App\Services\HabitLogService;
use App\Services\HabitRecurrence;
use App\Services\RoutineRecurrence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class HabitTestCase extends TestCase
{
    use RefreshDatabase;

    protected const TODAY = '2026-08-13';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(self::TODAY.' 09:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function createUser(
        string $email = 'owner@example.test',
        string $timezone = 'UTC',
        string $locale = 'en-GB',
    ): User {
        $user = User::factory()->create(['email' => $email, 'email_verified_at' => null]);
        $user->ensureProfile()->update(['timezone' => $timezone, 'locale' => $locale]);
        $user->unsetRelation('profile');

        return $user->fresh();
    }

    /** @param array<string, mixed> $attributes */
    protected function createHabit(User $user, array $attributes = [], array $weekdays = []): Habit
    {
        $modelAttributes = array_diff_key($attributes, array_flip([
            'schedule_type', 'preferred_time', 'starts_on', 'ends_on',
        ]));
        $habit = Habit::create([
            'user_id' => $user->id,
            'name' => 'Read',
            'kind' => Habit::KIND_HABIT,
            'mode' => Habit::MODE_YES_NO,
            ...$modelAttributes,
        ]);

        app(HabitRecurrence::class)->apply($habit, $user, [
            'schedule_type' => $weekdays === [] ? 'daily' : 'weekdays',
            'starts_on' => self::TODAY,
            ...array_intersect_key($attributes, array_flip([
                'schedule_type', 'preferred_time', 'starts_on', 'ends_on',
            ])),
        ], $weekdays);

        return $habit->fresh(['recurringRule.ruleWeekdays', 'limitSteps']);
    }

    protected function createRoutine(User $user, string $name = 'Morning routine'): Routine
    {
        $routine = Routine::create(['user_id' => $user->id, 'name' => $name]);
        app(RoutineRecurrence::class)->apply($routine, $user, ['schedule_type' => 'daily'], []);

        return $routine->fresh(['recurringRule']);
    }

    protected function createGoal(User $user, string $name = 'Read more'): Goal
    {
        return Goal::create(['user_id' => $user->id, 'name' => $name]);
    }

    protected function occurrenceOn(Habit $habit, string $date = self::TODAY): PlannedOccurrence
    {
        return PlannedOccurrence::query()
            ->where('recurring_rule_id', $habit->recurringRule->id)
            ->where(function ($query) use ($date): void {
                $query->where(function ($original) use ($date): void {
                    $original->where('occurrence_date', $date)->whereNull('rescheduled_to');
                })->orWhere('rescheduled_to', $date);
            })
            ->firstOrFail();
    }

    /** @param array<string, mixed> $data */
    protected function createLog(Habit $habit, User $user, string $date, array $data): HabitLog
    {
        return app(HabitLogService::class)->upsert($habit, $user, $date, $data);
    }
}
