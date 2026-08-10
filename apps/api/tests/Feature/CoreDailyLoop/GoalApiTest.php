<?php

namespace Tests\Feature\CoreDailyLoop;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;

class GoalApiTest extends CoreDailyLoopTestCase
{
    private const MONDAY = '2026-08-10';

    #[DataProvider('invalidGoalPayloads')]
    public function test_goal_creation_validates_the_contract(array $payload, string $field): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $this->postJson('/api/goals', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('goals', 0);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidGoalPayloads(): array
    {
        return [
            'blank name' => [['name' => '   '], 'name'],
            'name over 160 characters' => [['name' => str_repeat('n', 161)], 'name'],
            'description over 5000 characters' => [[
                'name' => 'Valid name',
                'description' => str_repeat('d', 5001),
            ], 'description'],
            'unsupported goal type' => [[
                'name' => 'Typed goal',
                'type' => 'body',
            ], 'type'],
            'unsupported lifecycle status' => [[
                'name' => 'Invalid status',
                'status' => 'paused',
            ], 'status'],
            'non-boolean archive state' => [[
                'name' => 'Invalid archive',
                'is_archived' => 'later',
            ], 'is_archived'],
            'non-canonical target date' => [[
                'name' => 'Dated goal',
                'target_date' => '2026-08-10T00:00:00Z',
            ], 'target_date'],
            'impossible target date' => [[
                'name' => 'Impossible goal',
                'target_date' => '2026-02-30',
            ], 'target_date'],
            'client supplied completion timestamp' => [[
                'name' => 'Forged completion',
                'completed_at' => '2026-08-10T08:00:00Z',
            ], 'completed_at'],
        ];
    }

    public function test_empty_goal_update_is_rejected(): void
    {
        $owner = $this->createUser();
        $goal = $this->createGoal($owner);
        $this->actingAs($owner);

        $this->patchJson("/api/goals/{$goal->id}", [])->assertUnprocessable();

        $this->assertSame('Stay consistent', $goal->fresh()->name);
    }

    public function test_goal_creation_returns_a_complete_fresh_goal_with_defaults(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $response = $this->postJson('/api/goals', [
            'name' => '  Build consistency  ',
            'description' => '  Keep showing up  ',
            'target_date' => '2026-12-31',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Build consistency')
            ->assertJsonPath('data.description', 'Keep showing up')
            ->assertJsonPath('data.type', 'general')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.target_date', '2026-12-31')
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.is_archived', false)
            ->assertJsonPath('data.archived_at', null)
            ->assertJsonPath('data.routines', [])
            ->assertJsonStructure([
                'data' => [
                    'id', 'name', 'description', 'type', 'status', 'target_date',
                    'completed_at', 'is_archived', 'archived_at', 'routines',
                ],
            ]);

        $this->assertDatabaseHas('goals', [
            'id' => $response->json('data.id'),
            'user_id' => $owner->id,
            'name' => 'Build consistency',
            'description' => 'Keep showing up',
            'type' => 'general',
            'status' => 'active',
            'is_archived' => false,
        ]);
    }

    public function test_goal_lists_are_partitioned_by_archive_state_and_owner(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test', 'Other Owner');
        $current = $this->createGoal($owner, ['name' => 'Current goal']);
        $archived = $this->createGoal($owner, [
            'name' => 'Archived goal',
            'is_archived' => true,
            'archived_at' => now(),
        ]);
        $otherGoal = $this->createGoal($other, ['name' => 'Other goal']);
        $this->actingAs($owner);

        $this->getJson('/api/goals')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $current->id)
            ->assertJsonMissing(['id' => $archived->id])
            ->assertJsonMissing(['id' => $otherGoal->id]);

        $this->getJson('/api/goals?archived=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $archived->id)
            ->assertJsonMissing(['id' => $current->id])
            ->assertJsonMissing(['id' => $otherGoal->id]);
    }

    public function test_goal_lifecycle_derives_and_preserves_completed_at(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 08:00:00 UTC');

        try {
            $owner = $this->createUser();
            $goal = $this->createGoal($owner);
            $this->actingAs($owner);

            $this->patchJson("/api/goals/{$goal->id}", ['status' => 'completed'])
                ->assertOk()
                ->assertJsonPath('data.status', 'completed')
                ->assertJsonPath('data.completed_at', '2026-08-10T08:00:00.000000Z');

            CarbonImmutable::setTestNow('2026-08-11 09:00:00 UTC');
            $this->patchJson("/api/goals/{$goal->id}", [
                'name' => 'Completed goal edited safely',
                'status' => 'completed',
            ])->assertOk()
                ->assertJsonPath('data.completed_at', '2026-08-10T08:00:00.000000Z');

            $this->patchJson("/api/goals/{$goal->id}", ['status' => 'abandoned'])
                ->assertOk()
                ->assertJsonPath('data.status', 'abandoned')
                ->assertJsonPath('data.completed_at', null);

            $this->patchJson("/api/goals/{$goal->id}", ['status' => 'active'])
                ->assertOk()
                ->assertJsonPath('data.status', 'active')
                ->assertJsonPath('data.completed_at', null);

            $this->patchJson("/api/goals/{$goal->id}", ['status' => 'completed'])
                ->assertOk()
                ->assertJsonPath('data.completed_at', '2026-08-11T09:00:00.000000Z');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_goal_creation_derives_completed_at_from_its_initial_status(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 08:00:00 UTC');

        try {
            $owner = $this->createUser();
            $this->actingAs($owner);

            $this->postJson('/api/goals', [
                'name' => 'Already completed',
                'status' => 'completed',
            ])->assertCreated()
                ->assertJsonPath('data.status', 'completed')
                ->assertJsonPath('data.completed_at', '2026-08-10T08:00:00.000000Z');

            $this->postJson('/api/goals', [
                'name' => 'Already abandoned',
                'status' => 'abandoned',
            ])->assertCreated()
                ->assertJsonPath('data.status', 'abandoned')
                ->assertJsonPath('data.completed_at', null);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_goal_archive_and_restore_manage_timestamps_idempotently(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');

        try {
            $owner = $this->createUser();
            $goal = $this->createGoal($owner);
            $routine = $this->createRoutine($owner);
            $this->linkGoalToRoutine($goal, $routine);
            $this->actingAs($owner);

            $this->patchJson("/api/goals/{$goal->id}", ['is_archived' => true])
                ->assertOk()
                ->assertJsonPath('data.is_archived', true)
                ->assertJsonPath('data.archived_at', '2026-08-10T12:00:00.000000Z');

            CarbonImmutable::setTestNow('2026-08-11 12:00:00 UTC');
            $this->patchJson("/api/goals/{$goal->id}", ['is_archived' => true])
                ->assertOk()
                ->assertJsonPath('data.archived_at', '2026-08-10T12:00:00.000000Z');

            $this->patchJson("/api/goals/{$goal->id}", ['is_archived' => false])
                ->assertOk()
                ->assertJsonPath('data.is_archived', false)
                ->assertJsonPath('data.archived_at', null);

            $this->patchJson("/api/goals/{$goal->id}", ['is_archived' => false])
                ->assertOk()
                ->assertJsonPath('data.archived_at', null);

            $this->assertDatabaseHas('goal_routine', [
                'user_id' => $owner->id,
                'goal_id' => $goal->id,
                'routine_id' => $routine->id,
            ]);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_link_and_unlink_are_idempotent_without_deleting_either_record(): void
    {
        $owner = $this->createUser();
        $goal = $this->createGoal($owner);
        $routine = $this->createRoutine($owner);
        $this->actingAs($owner);

        $this->postJson("/api/goals/{$goal->id}/routines/{$routine->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.routines')
            ->assertJsonPath('data.routines.0.id', $routine->id);
        $this->postJson("/api/goals/{$goal->id}/routines/{$routine->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.routines');

        $this->assertDatabaseCount('goal_routine', 1);

        $this->deleteJson("/api/goals/{$goal->id}/routines/{$routine->id}")->assertNoContent();
        $this->deleteJson("/api/goals/{$goal->id}/routines/{$routine->id}")->assertNoContent();

        $this->assertDatabaseCount('goal_routine', 0);
        $this->assertDatabaseHas('goals', ['id' => $goal->id]);
        $this->assertDatabaseHas('routines', ['id' => $routine->id]);
    }

    public function test_cross_owner_link_and_unlink_identifiers_are_always_not_found(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test', 'Other Owner');
        $ownerGoal = $this->createGoal($owner, ['name' => 'Owner goal']);
        $otherGoal = $this->createGoal($other, ['name' => 'Other goal']);
        $ownerRoutine = $this->createRoutine($owner, ['name' => 'Owner routine']);
        $otherRoutine = $this->createRoutine($other, ['name' => 'Other routine']);
        $this->linkGoalToRoutine($ownerGoal, $ownerRoutine);
        $this->linkGoalToRoutine($otherGoal, $otherRoutine);
        $this->actingAs($owner);

        foreach ([
            "/api/goals/{$ownerGoal->id}/routines/{$otherRoutine->id}",
            "/api/goals/{$otherGoal->id}/routines/{$ownerRoutine->id}",
            "/api/goals/{$otherGoal->id}/routines/{$otherRoutine->id}",
        ] as $path) {
            $this->postJson($path)->assertNotFound();
            $this->deleteJson($path)->assertNotFound();
        }

        $this->assertDatabaseCount('goal_routine', 2);
        $this->assertDatabaseHas('goal_routine', [
            'user_id' => $owner->id,
            'goal_id' => $ownerGoal->id,
            'routine_id' => $ownerRoutine->id,
        ]);
        $this->assertDatabaseHas('goal_routine', [
            'user_id' => $other->id,
            'goal_id' => $otherGoal->id,
            'routine_id' => $otherRoutine->id,
        ]);
    }

    public function test_today_shows_only_current_owner_active_non_archived_linked_goals(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test', 'Other Owner');
        $routine = $this->createRoutine($owner, ['name' => 'Scheduled routine']);
        $unscheduledRoutine = $this->createRoutine($owner, ['name' => 'Tuesday routine'], ['TU']);

        $active = $this->createGoal($owner, ['name' => 'Active context']);
        $completed = $this->createGoal($owner, [
            'name' => 'Completed context',
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $abandoned = $this->createGoal($owner, [
            'name' => 'Abandoned context',
            'status' => 'abandoned',
        ]);
        $archived = $this->createGoal($owner, [
            'name' => 'Archived context',
            'is_archived' => true,
            'archived_at' => now(),
        ]);
        $unscheduledContext = $this->createGoal($owner, ['name' => 'Unscheduled context']);
        $otherGoal = $this->createGoal($other, ['name' => 'Other owner context']);
        $otherRoutine = $this->createRoutine($other, ['name' => 'Other routine']);

        foreach ([$active, $completed, $abandoned, $archived] as $goal) {
            $this->linkGoalToRoutine($goal, $routine);
        }
        $this->linkGoalToRoutine($unscheduledContext, $unscheduledRoutine);
        $this->linkGoalToRoutine($otherGoal, $otherRoutine);
        $this->actingAs($owner);

        $this->getJson('/api/today?date='.self::MONDAY)
            ->assertOk()
            ->assertJsonCount(1, 'routines')
            ->assertJsonPath('routines.0.id', $routine->id)
            ->assertJsonCount(1, 'routines.0.goals')
            ->assertJsonPath('routines.0.goals.0.id', $active->id)
            ->assertJsonCount(1, 'goals')
            ->assertJsonPath('goals.0.id', $active->id)
            ->assertJsonMissing(['id' => $completed->id])
            ->assertJsonMissing(['id' => $abandoned->id])
            ->assertJsonMissing(['id' => $archived->id])
            ->assertJsonMissing(['id' => $unscheduledContext->id])
            ->assertJsonMissing(['id' => $otherGoal->id]);
    }
}
