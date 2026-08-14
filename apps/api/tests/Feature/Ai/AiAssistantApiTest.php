<?php

namespace Tests\Feature\Ai;

use App\Models\Item;
use App\Models\LlmConnection;
use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAssistantApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_connection_lifecycle_is_authenticated_masked_replace_only_and_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $this->postJson('/api/ai/connections', [])->assertUnauthorized();
        $this->actingAs($owner);

        $created = $this->postJson('/api/ai/connections', $this->connectionPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'untested')
            ->assertJsonPath('data.key_mask', '••••1234');
        $id = $created->json('data.id');
        $this->assertStringNotContainsString('fixture-provider-key-1234', $created->getContent());
        $this->assertStringNotContainsString('fixture-provider-key-1234', (string) DB::table('llm_connections')->where('id', $id)->value('api_key'));
        $this->getJson('/api/ai/settings')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonMissing(['api_key' => 'fixture-provider-key-1234']);

        $this->actingAs($foreign)->patchJson("/api/ai/connections/{$id}", ['name' => 'Stolen'])->assertNotFound();
        $this->deleteJson("/api/ai/connections/{$id}")->assertNotFound();
        $this->actingAs($owner)->patchJson("/api/ai/connections/{$id}", [
            'api_key' => 'rotated-provider-key-9876',
        ])->assertOk()->assertJsonPath('data.key_mask', '••••9876')->assertJsonPath('data.status', 'untested');
    }

    public function test_probe_must_succeed_before_activation_and_delete_clears_active_without_remote_call(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => 'SELFHANDLER_OK']], 'stop_reason' => 'end_turn',
        ])]);
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $id = $this->postJson('/api/ai/connections', $this->connectionPayload())->json('data.id');

        $this->postJson("/api/ai/connections/{$id}/activate")
            ->assertConflict()->assertJsonPath('code', 'ai_connection_not_ready');
        $this->postJson("/api/ai/connections/{$id}/test")
            ->assertOk()->assertJsonPath('data.status', 'ready');
        $this->postJson("/api/ai/connections/{$id}/activate")
            ->assertOk()->assertJsonPath('active_connection_id', $id);
        Http::assertSentCount(1);

        $this->deleteJson("/api/ai/connections/{$id}")->assertNoContent();
        $this->getJson('/api/ai/settings')->assertOk()->assertJsonPath('active_connection_id', null);
        Http::assertSentCount(1);
    }

    public function test_draft_requires_ready_active_connection_and_explicit_consent_before_network(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create();
        $item = Item::query()->create(['user_id' => $owner->id, 'title' => 'Sort me']);
        $this->actingAs($owner);

        $this->postJson('/api/ai/scenarios/storage-inbox/draft', ['item_id' => $item->id])
            ->assertConflict()->assertJsonPath('code', 'ai_active_connection_required');
        Http::assertNothingSent();

        $connection = $this->readyConnection($owner);
        DB::table('llm_settings')->insert([
            'user_id' => $owner->id, 'active_connection_id' => $connection->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson('/api/ai/scenarios/storage-inbox/draft', ['item_id' => $item->id])
            ->assertConflict()->assertJsonPath('code', 'ai_consent_required');
        Http::assertNothingSent();

        $this->putJson('/api/ai/consents/storage-inbox', ['granted' => true])
            ->assertOk()->assertJsonPath('data.granted', true);
    }

    public function test_draft_sends_minimal_context_makes_no_write_and_confirmation_applies_once(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.anthropic.com/v1/messages' => Http::response([
            'content' => [[
                'type' => 'tool_use', 'id' => 'toolu_test', 'name' => 'storage_triage_inbox_item',
                'input' => [
                    'type' => 'idea', 'project_id' => null, 'tags' => ['focus'], 'priority' => 'high',
                    'due_on' => '2026-08-20', 'rationale' => 'This looks like a focused idea.',
                ],
            ]],
            'stop_reason' => 'tool_use',
        ])]);
        $owner = User::factory()->create();
        $owner->ensureProfile()->update([
            'timezone' => 'Europe/Kyiv', 'locale' => 'en-GB', 'recommendation_tone' => 'neutral',
        ]);
        $connection = $this->readyConnection($owner);
        DB::table('llm_settings')->insert([
            'user_id' => $owner->id, 'active_connection_id' => $connection->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('llm_consents')->insert([
            'user_id' => $owner->id, 'scope' => 'storage_inbox', 'granted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Project::query()->create(['user_id' => $owner->id, 'name' => 'Owner project']);
        Tag::query()->create(['user_id' => $owner->id, 'name' => 'existing']);
        $item = Item::query()->create([
            'user_id' => $owner->id, 'title' => 'Think about launch', 'description' => 'Private selected note',
        ]);
        Item::query()->create(['user_id' => $owner->id, 'title' => 'Other private item']);
        $this->actingAs($owner);

        $draft = $this->postJson('/api/ai/scenarios/storage-inbox/draft', ['item_id' => $item->id])
            ->assertOk()->assertJsonPath('data.item_id', $item->id)
            ->assertJsonPath('data.proposal.type', 'idea')
            ->assertJsonPath('data.shared_scope', 'storage_inbox');

        $this->assertDatabaseHas('items', ['id' => $item->id, 'status' => 'inbox', 'type' => 'task']);
        $this->assertDatabaseMissing('tags', ['user_id' => $owner->id, 'name' => 'focus']);
        Http::assertSent(function (Request $request): bool {
            $body = json_encode($request->data());
            $context = json_decode($request->data()['messages'][0]['content'] ?? '', true)['context'] ?? null;

            return is_array($context)
                && array_keys($context['item'] ?? []) === ['title', 'description']
                && array_keys($context) === [
                    'item', 'projects', 'existing_tags', 'allowed_types', 'allowed_priorities', 'calendar', 'presentation',
                ]
                && str_contains($body, 'Think about launch')
                && str_contains($body, 'Private selected note')
                && str_contains($body, 'Owner project')
                && str_contains($body, 'existing')
                && ! str_contains($body, 'Other private item')
                && ! str_contains($body, 'finance')
                && ! str_contains($body, 'body_measurements')
                && ! str_contains($body, 'attachments');
        });

        $token = $draft->json('data.confirmation_token');
        $this->postJson('/api/ai/scenarios/storage-inbox/confirm', ['confirmation_token' => $token])
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.type', 'idea')
            ->assertJsonPath('data.tags.0.name', 'focus');
        $this->postJson('/api/ai/scenarios/storage-inbox/confirm', ['confirmation_token' => $token])
            ->assertConflict()->assertJsonPath('code', 'ai_confirmation_replayed');
        $this->assertDatabaseCount('tags', 2);
        $this->assertDatabaseHas('items', [
            'id' => $item->id, 'status' => 'active', 'type' => 'idea', 'priority' => 'high', 'due_on' => '2026-08-20',
        ]);
        Http::assertSentCount(1);
    }

    public function test_foreign_project_invalid_tool_stale_source_and_revoked_consent_fail_closed(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $foreignProject = Project::query()->create(['user_id' => $foreign->id, 'name' => 'Foreign']);
        $item = Item::query()->create(['user_id' => $owner->id, 'title' => 'Selected']);
        $this->configureReadyOwner($owner);
        $this->actingAs($owner);

        Http::fakeSequence('https://api.anthropic.com/v1/messages')
            ->push([
                'content' => [[
                    'type' => 'tool_use', 'id' => 'toolu_bad', 'name' => 'delete_everything',
                    'input' => ['type' => 'task'],
                ]], 'stop_reason' => 'tool_use',
            ])
            ->push([
                'content' => [[
                    'type' => 'tool_use', 'id' => 'toolu_foreign', 'name' => 'storage_triage_inbox_item',
                    'input' => [
                        'type' => 'task', 'project_id' => $foreignProject->id, 'tags' => [], 'priority' => null,
                        'due_on' => null, 'rationale' => 'Foreign project must fail.',
                    ],
                ]], 'stop_reason' => 'tool_use',
            ])
            ->push([
                'content' => [[
                    'type' => 'tool_use', 'id' => 'toolu_ok', 'name' => 'storage_triage_inbox_item',
                    'input' => [
                        'type' => 'task', 'project_id' => null, 'tags' => [], 'priority' => null,
                        'due_on' => null, 'rationale' => 'Valid draft.',
                    ],
                ]], 'stop_reason' => 'tool_use',
            ]);
        $this->postJson('/api/ai/scenarios/storage-inbox/draft', ['item_id' => $item->id])
            ->assertUnprocessable()->assertJsonPath('code', 'ai_tool_not_allowed');
        $this->assertDatabaseHas('items', ['id' => $item->id, 'status' => 'inbox']);

        $this->postJson('/api/ai/scenarios/storage-inbox/draft', ['item_id' => $item->id])
            ->assertUnprocessable()->assertJsonPath('code', 'ai_provider_invalid_response');

        $draft = $this->postJson('/api/ai/scenarios/storage-inbox/draft', ['item_id' => $item->id])->assertOk();
        $item->update(['title' => 'Changed after draft']);
        $this->postJson('/api/ai/scenarios/storage-inbox/confirm', [
            'confirmation_token' => $draft->json('data.confirmation_token'),
        ])->assertConflict()->assertJsonPath('code', 'ai_confirmation_stale');

        $this->putJson('/api/ai/consents/storage-inbox', ['granted' => false])->assertOk();
        $this->postJson('/api/ai/scenarios/storage-inbox/draft', ['item_id' => $item->id])
            ->assertConflict()->assertJsonPath('code', 'ai_consent_required');
    }

    private function connectionPayload(): array
    {
        return [
            'name' => 'Personal Claude', 'provider' => 'anthropic', 'model' => 'claude-test-model',
            'api_key' => 'fixture-provider-key-1234', 'parameters' => ['max_output_tokens' => 512],
        ];
    }

    private function readyConnection(User $owner): LlmConnection
    {
        return LlmConnection::query()->create([
            'user_id' => $owner->id, 'name' => 'Ready', 'provider' => 'anthropic', 'model' => 'claude-test-model',
            'api_key' => 'fixture-provider-key-1234', 'key_hint' => '1234',
            'parameters' => ['max_output_tokens' => 512], 'status' => 'ready', 'last_tested_at' => now(),
        ]);
    }

    private function configureReadyOwner(User $owner): void
    {
        $connection = $this->readyConnection($owner);
        DB::table('llm_settings')->insert([
            'user_id' => $owner->id, 'active_connection_id' => $connection->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('llm_consents')->insert([
            'user_id' => $owner->id, 'scope' => 'storage_inbox', 'granted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
