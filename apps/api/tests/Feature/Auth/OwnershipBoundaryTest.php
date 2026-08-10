<?php

namespace Tests\Feature\Auth;

use App\Models\DailyReview;
use App\Models\Goal;
use App\Models\Routine;
use App\Models\RoutineLog;
use App\Models\User;

class OwnershipBoundaryTest extends AuthTestCase
{
    private const DATE = '2026-08-09';

    public function test_each_account_reads_only_its_records_relationships_logs_reviews_and_today_totals(): void
    {
        [$userA, $userB, $routineA, $routineB, $goalA, $goalB] = $this->seedTwoAccounts();

        $this->actingAs($userA);
        $this->getJson('/api/routines')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $routineA->id)
            ->assertJsonPath('data.0.goals.0.id', $goalA->id)
            ->assertJsonMissing(['id' => $routineB->id])
            ->assertJsonMissing(['id' => $goalB->id]);
        $this->getJson('/api/goals')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $goalA->id)
            ->assertJsonPath('data.0.routines.0.id', $routineA->id)
            ->assertJsonMissing(['id' => $goalB->id])
            ->assertJsonMissing(['id' => $routineB->id]);
        $this->getJson('/api/daily-reviews/'.self::DATE)
            ->assertOk()
            ->assertJsonPath('data.user_id', $userA->id)
            ->assertJsonPath('data.notes', 'A review');
        $this->getJson('/api/today?date='.self::DATE)
            ->assertOk()
            ->assertJsonPath('summary.scheduled', 1)
            ->assertJsonPath('summary.done', 1)
            ->assertJsonPath('summary.skipped', 0)
            ->assertJsonCount(1, 'routines')
            ->assertJsonPath('routines.0.id', $routineA->id)
            ->assertJsonPath('routines.0.log.user_id', $userA->id)
            ->assertJsonCount(1, 'goals')
            ->assertJsonPath('goals.0.id', $goalA->id)
            ->assertJsonPath('review.user_id', $userA->id)
            ->assertJsonMissing(['id' => $routineB->id])
            ->assertJsonMissing(['id' => $goalB->id]);

        $this->actingAs($userB);
        $this->getJson('/api/routines')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $routineB->id)
            ->assertJsonMissing(['id' => $routineA->id]);
        $this->getJson('/api/goals')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $goalB->id)
            ->assertJsonMissing(['id' => $goalA->id]);
        $this->getJson('/api/daily-reviews/'.self::DATE)
            ->assertOk()
            ->assertJsonPath('data.user_id', $userB->id)
            ->assertJsonPath('data.notes', 'B review');
        $this->getJson('/api/today?date='.self::DATE)
            ->assertOk()
            ->assertJsonPath('summary.scheduled', 1)
            ->assertJsonPath('summary.done', 0)
            ->assertJsonPath('summary.skipped', 1)
            ->assertJsonCount(1, 'routines')
            ->assertJsonPath('routines.0.id', $routineB->id)
            ->assertJsonPath('review.user_id', $userB->id)
            ->assertJsonMissing(['id' => $routineA->id])
            ->assertJsonMissing(['id' => $goalA->id]);
    }

    public function test_foreign_identifiers_are_404_and_cannot_mutate_or_partially_link_records(): void
    {
        [$userA, $userB, $routineA, $routineB, $goalA, $goalB] = $this->seedTwoAccounts();
        $this->actingAs($userA);

        $this->patchJson("/api/routines/{$routineB->id}", ['name' => 'Stolen routine'])->assertNotFound();
        $this->putJson("/api/routines/{$routineB->id}/logs/".self::DATE, ['status' => 'done'])->assertNotFound();
        $this->deleteJson("/api/routines/{$routineB->id}/logs/".self::DATE)->assertNotFound();

        $this->patchJson("/api/goals/{$goalB->id}", ['name' => 'Stolen goal'])->assertNotFound();
        $this->postJson("/api/goals/{$goalA->id}/routines/{$routineB->id}")->assertNotFound();
        $this->postJson("/api/goals/{$goalB->id}/routines/{$routineA->id}")->assertNotFound();
        $this->deleteJson("/api/goals/{$goalA->id}/routines/{$routineB->id}")->assertNotFound();
        $this->deleteJson("/api/goals/{$goalB->id}/routines/{$routineA->id}")->assertNotFound();

        $this->assertDatabaseHas('routines', [
            'id' => $routineB->id,
            'user_id' => $userB->id,
            'name' => 'Shared title',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('goals', [
            'id' => $goalB->id,
            'user_id' => $userB->id,
            'name' => 'B goal',
        ]);
        $this->assertDatabaseHas('routine_logs', [
            'user_id' => $userB->id,
            'routine_id' => $routineB->id,
            'status' => 'skipped',
        ]);
        $this->assertDatabaseMissing('goal_routine', [
            'user_id' => $userA->id,
            'goal_id' => $goalA->id,
            'routine_id' => $routineB->id,
        ]);
        $this->assertDatabaseMissing('goal_routine', [
            'user_id' => $userA->id,
            'goal_id' => $goalB->id,
            'routine_id' => $routineA->id,
        ]);
        $this->assertDatabaseHas('goal_routine', [
            'user_id' => $userA->id,
            'goal_id' => $goalA->id,
            'routine_id' => $routineA->id,
        ]);
        $this->assertDatabaseHas('goal_routine', [
            'user_id' => $userB->id,
            'goal_id' => $goalB->id,
            'routine_id' => $routineB->id,
        ]);
    }

    public function test_server_derives_every_write_owner_and_allows_account_scoped_titles_and_dates(): void
    {
        $userA = $this->createUser('a@example.test', name: 'Account A');
        $userB = $this->createUser('b@example.test', name: 'Account B');

        $this->actingAs($userA);
        $routineAId = $this->postJson('/api/routines', [
            'name' => 'Same title',
            'schedule_type' => 'daily',
            'user_id' => $userB->id,
        ])->assertCreated()->json('data.id');
        $goalAId = $this->postJson('/api/goals', [
            'name' => 'Same goal',
            'user_id' => $userB->id,
        ])->assertCreated()->json('data.id');
        $this->putJson('/api/routines/'.$routineAId.'/logs/'.self::DATE, [
            'status' => 'done',
            'user_id' => $userB->id,
        ])->assertOk();
        $this->putJson('/api/daily-reviews/'.self::DATE, [
            'mood' => 8,
            'notes' => 'A review',
            'user_id' => $userB->id,
        ])->assertOk();
        $this->postJson('/api/goals/'.$goalAId.'/routines/'.$routineAId, [
            'user_id' => $userB->id,
        ])->assertOk();

        $this->actingAs($userB);
        $routineBId = $this->postJson('/api/routines', [
            'name' => 'Same title',
            'schedule_type' => 'daily',
            'user_id' => $userA->id,
        ])->assertCreated()->json('data.id');
        $goalBId = $this->postJson('/api/goals', [
            'name' => 'Same goal',
            'user_id' => $userA->id,
        ])->assertCreated()->json('data.id');
        $this->putJson('/api/routines/'.$routineBId.'/logs/'.self::DATE, [
            'status' => 'skipped',
            'user_id' => $userA->id,
        ])->assertOk();
        $this->putJson('/api/daily-reviews/'.self::DATE, [
            'mood' => 6,
            'notes' => 'B review',
            'user_id' => $userA->id,
        ])->assertOk();
        $this->postJson('/api/goals/'.$goalBId.'/routines/'.$routineBId, [
            'user_id' => $userA->id,
        ])->assertOk();

        $this->assertDatabaseHas('routines', ['id' => $routineAId, 'user_id' => $userA->id]);
        $this->assertDatabaseHas('routines', ['id' => $routineBId, 'user_id' => $userB->id]);
        $this->assertDatabaseHas('goals', ['id' => $goalAId, 'user_id' => $userA->id]);
        $this->assertDatabaseHas('goals', ['id' => $goalBId, 'user_id' => $userB->id]);
        $this->assertDatabaseHas('routine_logs', ['routine_id' => $routineAId, 'user_id' => $userA->id]);
        $this->assertDatabaseHas('routine_logs', ['routine_id' => $routineBId, 'user_id' => $userB->id]);
        $this->assertTrue(DailyReview::query()->where('user_id', $userA->id)->whereDate('review_date', self::DATE)->exists());
        $this->assertTrue(DailyReview::query()->where('user_id', $userB->id)->whereDate('review_date', self::DATE)->exists());
        $this->assertDatabaseHas('goal_routine', ['goal_id' => $goalAId, 'routine_id' => $routineAId, 'user_id' => $userA->id]);
        $this->assertDatabaseHas('goal_routine', ['goal_id' => $goalBId, 'routine_id' => $routineBId, 'user_id' => $userB->id]);
        $this->assertDatabaseCount('routines', 2);
        $this->assertDatabaseCount('goals', 2);
        $this->assertDatabaseCount('routine_logs', 2);
        $this->assertDatabaseCount('daily_reviews', 2);
        $this->assertDatabaseCount('goal_routine', 2);
    }

    /**
     * @return array{User, User, Routine, Routine, Goal, Goal}
     */
    private function seedTwoAccounts(): array
    {
        $userA = $this->createUser('a@example.test', name: 'Account A');
        $userB = $this->createUser('b@example.test', name: 'Account B');

        $routineA = Routine::create([
            'user_id' => $userA->id,
            'name' => 'Shared title',
            'schedule_type' => 'daily',
        ]);
        $routineB = Routine::create([
            'user_id' => $userB->id,
            'name' => 'Shared title',
            'schedule_type' => 'daily',
        ]);
        $goalA = Goal::create(['user_id' => $userA->id, 'name' => 'A goal']);
        $goalB = Goal::create(['user_id' => $userB->id, 'name' => 'B goal']);

        $goalA->routines()->attach($routineA->id, ['user_id' => $userA->id]);
        $goalB->routines()->attach($routineB->id, ['user_id' => $userB->id]);

        RoutineLog::create([
            'user_id' => $userA->id,
            'routine_id' => $routineA->id,
            'log_date' => self::DATE,
            'status' => 'done',
            'completed_at' => now(),
        ]);
        RoutineLog::create([
            'user_id' => $userB->id,
            'routine_id' => $routineB->id,
            'log_date' => self::DATE,
            'status' => 'skipped',
        ]);
        DailyReview::create([
            'user_id' => $userA->id,
            'review_date' => self::DATE,
            'notes' => 'A review',
            'completed_at' => now(),
        ]);
        DailyReview::create([
            'user_id' => $userB->id,
            'review_date' => self::DATE,
            'notes' => 'B review',
            'completed_at' => now(),
        ]);

        return [$userA, $userB, $routineA, $routineB, $goalA, $goalB];
    }
}
