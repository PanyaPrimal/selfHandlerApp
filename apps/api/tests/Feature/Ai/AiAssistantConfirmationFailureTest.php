<?php

namespace Tests\Feature\Ai;

use App\Models\Item;
use App\Models\LlmAuditEvent;
use App\Models\LlmConnection;
use App\Models\LlmConsent;
use App\Models\LlmSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAssistantConfirmationFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_or_non_inbox_item_is_hidden_before_any_provider_request(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $foreignItem = Item::query()->create(['user_id' => $foreign->id, 'title' => 'Foreign private item']);
        $activeItem = Item::query()->create([
            'user_id' => $owner->id,
            'title' => 'Already active',
            'status' => Item::STATUS_ACTIVE,
        ]);
        $this->configureReadyOwner($owner);
        $this->actingAs($owner);

        $this->postJson('/api/ai/scenarios/storage-inbox/draft', ['item_id' => $foreignItem->id])
            ->assertNotFound();
        $this->postJson('/api/ai/scenarios/storage-inbox/draft', ['item_id' => $activeItem->id])
            ->assertNotFound();
        Http::assertNothingSent();
        $this->assertDatabaseCount('llm_tool_confirmations', 0);
    }

    public function test_expiry_foreign_owner_source_status_connection_switch_revoke_and_delete_fail_closed(): void
    {
        Http::preventStrayRequests();
        $sequence = Http::fakeSequence('https://api.anthropic.com/v1/messages');
        foreach (range(1, 6) as $unused) {
            $sequence->push($this->validProviderResponse());
        }
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $connection = $this->configureReadyOwner($owner);
        $this->actingAs($owner);

        $foreignDraft = $this->draft($owner, 'Foreign confirmation');
        $this->actingAs($foreign)->postJson('/api/ai/scenarios/storage-inbox/confirm', [
            'confirmation_token' => $foreignDraft['token'],
        ])->assertConflict()->assertJsonPath('code', 'ai_confirmation_stale');
        $this->assertDatabaseHas('items', ['id' => $foreignDraft['item']->id, 'status' => Item::STATUS_INBOX]);

        $this->actingAs($owner);
        $expired = $this->draft($owner, 'Expired confirmation');
        DB::table('llm_tool_confirmations')->where('token_hash', hash('sha256', $expired['token']))
            ->update(['expires_at' => now()->subSecond()]);
        $this->confirm($expired['token'], 'ai_confirmation_expired');

        $statusChanged = $this->draft($owner, 'Status changed');
        $statusChanged['item']->update(['status' => Item::STATUS_ACTIVE]);
        $this->confirm($statusChanged['token'], 'ai_confirmation_stale');

        $switched = $this->draft($owner, 'Connection switched');
        $second = $this->readyConnection($owner, 'Second');
        LlmSetting::query()->where('user_id', $owner->id)->update(['active_connection_id' => $second->id]);
        $this->confirm($switched['token'], 'ai_confirmation_stale');
        LlmSetting::query()->where('user_id', $owner->id)->update(['active_connection_id' => $connection->id]);

        $revoked = $this->draft($owner, 'Consent revoked');
        $this->putJson('/api/ai/consents/storage-inbox', ['granted' => false])->assertOk();
        $this->confirm($revoked['token'], 'ai_confirmation_replayed');
        $this->putJson('/api/ai/consents/storage-inbox', ['granted' => true])->assertOk();

        $deleted = $this->draft($owner, 'Connection deleted');
        $this->deleteJson("/api/ai/connections/{$connection->id}")->assertNoContent();
        $this->confirm($deleted['token'], 'ai_confirmation_stale');

        Http::assertSentCount(6);
        $this->assertDatabaseMissing('tags', ['user_id' => $owner->id, 'name' => 'failure-safe']);
        $this->assertDatabaseHas('items', ['id' => $expired['item']->id, 'status' => Item::STATUS_INBOX]);
        $this->assertDatabaseHas('items', ['id' => $switched['item']->id, 'status' => Item::STATUS_INBOX]);
        $this->assertDatabaseHas('items', ['id' => $revoked['item']->id, 'status' => Item::STATUS_INBOX]);
        $this->assertDatabaseHas('items', ['id' => $deleted['item']->id, 'status' => Item::STATUS_INBOX]);
        $this->assertDatabaseMissing('llm_tool_confirmations', [
            'token_hash' => hash('sha256', $deleted['token']),
        ]);

        $audit = json_encode(DB::table('llm_audit_events')->get(), JSON_THROW_ON_ERROR);
        foreach (['Foreign confirmation', 'Expired confirmation', 'A private rationale', 'fixture-provider-key'] as $private) {
            $this->assertStringNotContainsString($private, $audit);
        }
        $this->assertGreaterThanOrEqual(5, LlmAuditEvent::query()
            ->where('event', LlmAuditEvent::EVENT_CONFIRMATION_REJECTED)->count());
    }

    /** @return array{item:Item,token:string} */
    private function draft(User $owner, string $title): array
    {
        $item = Item::query()->create(['user_id' => $owner->id, 'title' => $title]);
        $response = $this->postJson('/api/ai/scenarios/storage-inbox/draft', ['item_id' => $item->id])
            ->assertOk();

        return ['item' => $item, 'token' => $response->json('data.confirmation_token')];
    }

    private function confirm(string $token, string $code): void
    {
        $this->postJson('/api/ai/scenarios/storage-inbox/confirm', ['confirmation_token' => $token])
            ->assertConflict()->assertJsonPath('code', $code);
    }

    private function configureReadyOwner(User $owner): LlmConnection
    {
        $connection = $this->readyConnection($owner, 'Primary');
        LlmSetting::query()->create(['user_id' => $owner->id, 'active_connection_id' => $connection->id]);
        LlmConsent::query()->create([
            'user_id' => $owner->id,
            'scope' => LlmConsent::SCOPE_STORAGE_INBOX,
            'granted_at' => now(),
        ]);

        return $connection;
    }

    private function readyConnection(User $owner, string $name): LlmConnection
    {
        return LlmConnection::query()->create([
            'user_id' => $owner->id,
            'name' => $name,
            'provider' => LlmConnection::PROVIDER_ANTHROPIC,
            'model' => 'fixture-model',
            'api_key' => 'fixture-provider-key-1234',
            'key_hint' => '1234',
            'parameters' => ['max_output_tokens' => 512],
            'status' => LlmConnection::STATUS_READY,
            'last_tested_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function validProviderResponse(): array
    {
        return [
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_fixture',
                'name' => 'storage_triage_inbox_item',
                'input' => [
                    'type' => 'task',
                    'project_id' => null,
                    'tags' => ['failure-safe'],
                    'priority' => 'normal',
                    'due_on' => null,
                    'rationale' => 'A private rationale.',
                ],
            ]],
            'stop_reason' => 'tool_use',
        ];
    }
}
