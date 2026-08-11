<?php

namespace Tests\Feature\Recurrence;

use App\Models\RecurringRule;
use App\Models\Routine;
use App\Models\User;
use App\Services\RoutineRecurrence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared fixtures for the shared recurrence boundary.
 *
 * Routines are built through the same translation the API uses, so a fixture can
 * never produce a schedule shape the product cannot.
 */
abstract class RecurrenceTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Materialization is anchored to the current day, so the whole suite runs on
     * a fixed clock: creating a routine and materializing it explicitly then
     * produce the same window instead of two overlapping ones.
     */
    protected const TODAY = '2026-08-10';

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

    protected function createUser(string $email = 'owner@example.test', string $timezone = 'UTC'): User
    {
        $user = User::factory()->create(['email' => $email, 'email_verified_at' => null]);
        $user->ensureProfile()->update(['timezone' => $timezone]);
        $user->unsetRelation('profile');

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $schedule
     * @param  list<string>  $weekdays
     */
    protected function createRoutine(
        User $user,
        array $schedule = [],
        array $weekdays = [],
        array $attributes = [],
    ): Routine {
        $routine = Routine::create([
            'user_id' => $user->id,
            'name' => 'Morning walk',
            ...$attributes,
        ]);

        app(RoutineRecurrence::class)->apply(
            $routine,
            $user,
            ['schedule_type' => $weekdays === [] ? 'daily' : 'weekdays', ...$schedule],
            $weekdays,
        );

        return $routine->fresh(['recurringRule.ruleWeekdays']);
    }

    protected function ruleFor(Routine $routine): RecurringRule
    {
        return RecurringRule::query()
            ->where('owner_type', RecurringRule::OWNER_ROUTINE)
            ->where('owner_id', $routine->id)
            ->firstOrFail();
    }
}
