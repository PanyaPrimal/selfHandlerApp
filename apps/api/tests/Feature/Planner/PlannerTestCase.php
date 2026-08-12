<?php

namespace Tests\Feature\Planner;

use App\Models\Item;
use App\Models\PlannedOccurrence;
use App\Models\Routine;
use App\Models\TimeBlock;
use App\Models\User;
use App\Services\RoutineRecurrence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class PlannerTestCase extends TestCase
{
    use RefreshDatabase;

    /** The window is anchored to the current day, so the clock is fixed. */
    protected const TODAY = '2026-08-12';

    protected const TOMORROW = '2026-08-13';

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

    /** A daily routine with its rule and a materialized window. */
    protected function createRoutine(User $user, string $name = 'Morning walk', array $schedule = []): Routine
    {
        $routine = Routine::create(['user_id' => $user->id, 'name' => $name]);

        app(RoutineRecurrence::class)->apply(
            $routine,
            $user,
            ['schedule_type' => 'daily', ...$schedule],
            [],
        );

        return $routine->fresh(['recurringRule']);
    }

    protected function occurrenceOn(Routine $routine, string $date): PlannedOccurrence
    {
        return PlannedOccurrence::query()
            ->where('recurring_rule_id', $routine->recurringRule->id)
            ->where('occurrence_date', $date)
            ->firstOrFail();
    }

    protected function createItem(User $user, string $title, ?string $dueOn = null): Item
    {
        return Item::create([
            'user_id' => $user->id,
            'title' => $title,
            'status' => Item::STATUS_ACTIVE,
            'due_on' => $dueOn,
        ]);
    }

    protected function createBlock(User $user, array $attributes = []): TimeBlock
    {
        return TimeBlock::create([
            'user_id' => $user->id,
            'title' => 'Dentist',
            'block_date' => self::TODAY,
            ...$attributes,
        ]);
    }
}
