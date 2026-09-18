<?php

namespace Tests\Feature\Auth;

use App\Models\RecurringRule;
use App\Models\Routine;
use App\Models\User;
use Illuminate\Support\Str;

class WorkspaceSyncTest extends AuthTestCase
{
    public function test_account_guard_prevents_replay_after_session_switch(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->actingAs($second)->withHeader('X-Workspace-Account', (string) $first->id)
            ->postJson('/api/storage/items', ['title' => 'First account draft'])
            ->assertConflict()->assertJsonPath('code', 'sync_account_changed');
        $this->assertDatabaseCount('items', 0);
        $this->getJson('/api/mentor/turns')->assertConflict();
        $this->getJson('/api/storage/items')->assertConflict();
    }

    public function test_lost_acknowledgement_can_be_retried_without_creating_a_second_record(): void
    {
        $this->actingAs($this->createUser());
        $headers = ['X-Workspace-Operation' => (string) Str::uuid(), 'X-Workspace-Base' => '0'];
        $body = ['name' => 'Offline routine', 'schedule_type' => 'daily'];
        $created = $this->postJson('/api/routines', $body, $headers)->assertCreated();
        $this->postJson('/api/routines', $body, $headers)->assertCreated()
            ->assertExactJson($created->json())->assertHeader('X-Workspace-Replayed', 'true');
        $this->postJson('/api/routines', array_reverse($body, true), $headers)->assertCreated()
            ->assertHeader('X-Workspace-Replayed', 'true');
        $this->assertDatabaseCount('routines', 1);
        $this->assertDatabaseCount('workspace_receipts', 1);
        $this->getJson('/api/workspace/operations/'.$headers['X-Workspace-Operation'])
            ->assertOk()->assertJsonPath('acknowledged', true);
        $this->actingAs($this->createUser('receipt-other@example.test'))
            ->getJson('/api/workspace/operations/'.$headers['X-Workspace-Operation'])
            ->assertOk()->assertJsonPath('acknowledged', false);
    }

    public function test_background_materialization_invalidates_an_older_offline_snapshot(): void
    {
        $user = $this->createUser();
        $created = $this->actingAs($user)->postJson('/api/routines', ['name' => 'Daily', 'schedule_type' => 'daily'])->assertCreated();
        $id = $created->json('data.id');
        $base = $this->getJson('/api/workspace/revision')->json('revision');
        RecurringRule::query()->where('user_id', $user->id)->update(['last_materialized_until' => null]);
        $this->artisan('recurrence:materialize', ['--user' => $user->id])->assertSuccessful();
        $this->patchJson('/api/routines/'.$id, ['name' => 'Offline name'], [
            'X-Workspace-Operation' => (string) Str::uuid(), 'X-Workspace-Base' => (string) $base,
        ])->assertConflict();
        $this->assertDatabaseHas('routines', ['id' => $id, 'name' => 'Daily']);
    }

    public function test_old_clients_advance_revision_and_stale_writes_do_not_overwrite_them(): void
    {
        $user = $this->createUser();
        $routine = Routine::create(['user_id' => $user->id, 'name' => 'Initial']);
        $this->actingAs($user)->getJson('/api/routines')->assertHeader('X-Workspace-Revision', '0');
        $this->patchJson('/api/routines/'.$routine->id, ['name' => 'Other device'])->assertOk();
        $this->patchJson('/api/routines/'.$routine->id, ['name' => 'Offline edit'], [
            'X-Workspace-Operation' => (string) Str::uuid(), 'X-Workspace-Base' => '0',
        ])->assertConflict()->assertJsonPath('code', 'sync_conflict');
        $this->assertSame('Other device', $routine->fresh()->name);
    }

    public function test_operation_ids_cannot_be_reused_with_different_input_and_are_owner_scoped(): void
    {
        $a = $this->createUser();
        $b = $this->createUser('b@example.test');
        $headers = ['X-Workspace-Operation' => (string) Str::uuid()];
        $this->actingAs($a)->postJson('/api/routines', ['name' => 'A', 'schedule_type' => 'daily'], $headers)->assertCreated();
        $this->postJson('/api/routines', ['name' => 'Changed'], $headers)->assertConflict()
            ->assertJsonPath('code', 'sync_operation_reused');
        $this->actingAs($b)->postJson('/api/routines', ['name' => 'B', 'schedule_type' => 'daily'], $headers)->assertCreated();
        $this->getJson('/api/routines')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'B');
    }

    public function test_invalid_commands_do_not_advance_the_revision_or_create_receipts(): void
    {
        $this->actingAs($this->createUser());
        $this->postJson('/api/routines', ['name' => ''], [
            'X-Workspace-Operation' => (string) Str::uuid(), 'X-Workspace-Base' => '0',
        ])->assertUnprocessable();
        $this->getJson('/api/routines')->assertHeader('X-Workspace-Revision', '0');
        $this->assertDatabaseCount('workspace_receipts', 0);
        $this->postJson('/api/routines', ['name' => 'Invalid header'], ['X-Workspace-Base' => '-1'])->assertUnprocessable();
    }
}
