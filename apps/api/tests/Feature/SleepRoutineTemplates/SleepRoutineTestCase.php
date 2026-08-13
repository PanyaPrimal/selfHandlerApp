<?php

namespace Tests\Feature\SleepRoutineTemplates;

use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\Routine;
use App\Models\RoutineActivity;
use App\Models\SleepPlan;
use App\Models\User;
use App\Services\RoutineRecurrence;
use App\Services\SleepPlanRecurrence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class SleepRoutineTestCase extends TestCase
{
    use RefreshDatabase;

    protected const TODAY = '2026-08-13';

    protected const TOMORROW = '2026-08-14';

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

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $schedule
     * @param  list<string>  $weekdays
     */
    protected function createRoutine(
        User $user,
        array $attributes = [],
        array $schedule = [],
        array $weekdays = [],
    ): Routine {
        $routine = Routine::create([
            'user_id' => $user->id,
            'name' => 'Morning reset',
            'day_period' => Routine::DAY_PERIOD_MORNING,
            ...$attributes,
        ]);

        app(RoutineRecurrence::class)->apply($routine, $user, [
            'schedule_type' => $weekdays === [] ? 'daily' : 'weekdays',
            'starts_on' => self::TODAY,
            ...$schedule,
        ], $weekdays);

        return $routine->fresh(['recurringRule.ruleWeekdays', 'activities']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $schedule
     * @param  list<string>  $weekdays
     */
    protected function createSleepPlan(
        User $user,
        array $attributes = [],
        array $schedule = [],
        array $weekdays = [],
    ): SleepPlan {
        $plan = SleepPlan::create([
            'user_id' => $user->id,
            'name' => 'Regular night',
            'planned_wake_time' => '07:00',
            ...$attributes,
        ]);

        app(SleepPlanRecurrence::class)->apply($plan, $user, [
            'schedule_type' => $weekdays === [] ? 'daily' : 'weekdays',
            'planned_bed_time' => '23:00',
            'starts_on' => self::TODAY,
            ...$schedule,
        ], $weekdays);

        return $plan->fresh(['recurringRule.ruleWeekdays', 'logs']);
    }

    protected function sleepOccurrenceOn(SleepPlan $plan, string $date = self::TODAY): PlannedOccurrence
    {
        return PlannedOccurrence::query()
            ->whereIn('recurring_rule_id', RecurringRule::query()
                ->where('owner_type', RecurringRule::OWNER_SLEEP_PLAN)
                ->where('owner_id', $plan->id)
                ->select('id'))
            ->where(function ($query) use ($date): void {
                $query->where(function ($original) use ($date): void {
                    $original->where('occurrence_date', $date)->whereNull('rescheduled_to');
                })->orWhere('rescheduled_to', $date);
            })
            ->firstOrFail();
    }

    protected function routineOccurrenceOn(Routine $routine, string $date = self::TODAY): PlannedOccurrence
    {
        return PlannedOccurrence::query()
            ->where('recurring_rule_id', $routine->recurringRule->id)
            ->where(function ($query) use ($date): void {
                $query->where(function ($original) use ($date): void {
                    $original->where('occurrence_date', $date)->whereNull('rescheduled_to');
                })->orWhere('rescheduled_to', $date);
            })
            ->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    protected function createActivity(Routine $routine, array $attributes = []): RoutineActivity
    {
        return RoutineActivity::create([
            'user_id' => $routine->user_id,
            'routine_id' => $routine->id,
            'name' => 'Drink water',
            'sort_order' => 0,
            ...$attributes,
        ]);
    }
}
