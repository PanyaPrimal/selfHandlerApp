<?php

namespace Tests\Feature\CoreDailyLoop;

use App\Models\DailyReview;
use App\Models\Goal;
use App\Models\Routine;
use App\Models\RoutineLog;
use App\Models\User;
use App\Services\RoutineRecurrence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared fixtures for the core daily loop.
 *
 * Every helper takes an explicit owner so a test can never accidentally create
 * a record that belongs to nobody or to the wrong account.
 */
abstract class CoreDailyLoopTestCase extends TestCase
{
    use RefreshDatabase;

    protected function createUser(string $email = 'owner@example.test', string $name = 'Routine Owner'): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $weekdays
     */
    protected function createRoutine(User $user, array $attributes = [], array $weekdays = []): Routine
    {
        // The schedule now lives on the recurrence rule, so the fixture keeps the
        // feature 001 vocabulary and translates it exactly as the API does.
        $schedule = ['schedule_type' => $weekdays === [] ? 'daily' : 'weekdays'];

        foreach (['schedule_type', 'preferred_time', 'starts_on', 'ends_on'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $schedule[$field] = $attributes[$field];
                unset($attributes[$field]);
            }
        }

        $routine = Routine::create([
            'user_id' => $user->id,
            'name' => 'Morning walk',
            ...$attributes,
        ]);

        app(RoutineRecurrence::class)->apply($routine, $user, $schedule, $weekdays);

        return $routine->fresh(['goals', 'recurringRule.ruleWeekdays']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createLog(
        Routine $routine,
        string $date,
        string $status = 'done',
        array $attributes = [],
    ): RoutineLog {
        return RoutineLog::create([
            'user_id' => $routine->user_id,
            'routine_id' => $routine->id,
            'log_date' => $date,
            'status' => $status,
            'completed_at' => $status === 'done' ? now() : null,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createGoal(User $user, array $attributes = []): Goal
    {
        return Goal::create([
            'user_id' => $user->id,
            'name' => 'Stay consistent',
            ...$attributes,
        ]);
    }

    protected function linkGoalToRoutine(Goal $goal, Routine $routine): void
    {
        $goal->routines()->syncWithoutDetaching([
            $routine->id => ['user_id' => $goal->user_id],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createReview(User $user, string $date, array $attributes = []): DailyReview
    {
        return DailyReview::create([
            'user_id' => $user->id,
            'review_date' => $date,
            'completed_at' => now(),
            ...$attributes,
        ]);
    }
}
