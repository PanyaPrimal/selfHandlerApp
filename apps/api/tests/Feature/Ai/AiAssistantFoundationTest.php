<?php

namespace Tests\Feature\Ai;

use App\Models\Item;
use App\Models\LlmAuditEvent;
use App\Models\LlmConnection;
use App\Models\LlmConsent;
use App\Models\LlmSetting;
use App\Models\LlmToolConfirmation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class AiAssistantFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_tables_are_additive_owned_and_complete(): void
    {
        foreach (['llm_connections', 'llm_settings', 'llm_consents', 'llm_tool_confirmations', 'llm_audit_events'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'user_id'), "{$table} must be owner scoped.");
        }

        $this->assertTrue(Schema::hasColumns('llm_connections', [
            'name', 'provider', 'model', 'api_key', 'key_hint', 'parameters', 'status',
            'last_tested_at', 'last_used_at', 'last_error_code',
        ]));
        $this->assertTrue(Schema::hasColumns('llm_settings', ['active_connection_id']));
        $this->assertTrue(Schema::hasColumns('llm_consents', ['scope', 'granted_at', 'revoked_at']));
        $this->assertTrue(Schema::hasColumns('llm_tool_confirmations', [
            'llm_connection_id', 'token_hash', 'proposal_hash', 'tool_name', 'source_type', 'source_id',
            'source_fingerprint', 'status', 'expires_at', 'applied_at', 'rejected_at',
        ]));
        $this->assertTrue(Schema::hasColumns('llm_audit_events', [
            'llm_connection_id', 'event', 'scope', 'outcome', 'error_code', 'occurred_at',
        ]));
    }

    public function test_connection_key_is_encrypted_hidden_masked_and_parameters_are_closed(): void
    {
        $owner = User::factory()->create();
        $connection = LlmConnection::query()->create([
            'user_id' => $owner->id,
            'name' => 'Personal Claude',
            'provider' => LlmConnection::PROVIDER_ANTHROPIC,
            'model' => 'claude-test-model',
            'api_key' => 'secret-provider-key-1234',
            'key_hint' => '1234',
            'parameters' => ['max_output_tokens' => 512],
        ]);

        $raw = DB::table('llm_connections')->where('id', $connection->id)->first();
        $this->assertStringNotContainsString('secret-provider-key-1234', (string) $raw->api_key);
        $this->assertStringNotContainsString('secret-provider-key-1234', json_encode($connection));
        $this->assertSame('••••1234', $connection->keyMask());
        $this->assertSame(['max_output_tokens' => 512], $connection->parameters);
        $this->assertSame(LlmConnection::STATUS_UNTESTED, $connection->status);

        $this->expectException(LogicException::class);
        LlmConnection::query()->create([
            'user_id' => $owner->id,
            'name' => 'Unsafe',
            'provider' => 'custom',
            'model' => 'model',
            'api_key' => 'another-provider-key',
            'key_hint' => 'rkey',
            'parameters' => ['base_url' => 'http://127.0.0.1'],
        ]);
    }

    public function test_connection_names_are_unique_per_owner_not_globally(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        foreach ([$first, $second] as $user) {
            LlmConnection::query()->create([
                'user_id' => $user->id, 'name' => 'Primary', 'provider' => LlmConnection::PROVIDER_OPENAI,
                'model' => 'gpt-test', 'api_key' => 'provider-key-'.$user->id.'-0000', 'key_hint' => '0000',
            ]);
        }

        $this->expectException(QueryException::class);
        LlmConnection::query()->create([
            'user_id' => $first->id, 'name' => 'Primary', 'provider' => LlmConnection::PROVIDER_ANTHROPIC,
            'model' => 'claude-test', 'api_key' => 'duplicate-provider-key', 'key_hint' => '-key',
        ]);
    }

    public function test_active_setting_requires_a_ready_same_owner_connection(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $connection = LlmConnection::query()->create([
            'user_id' => $owner->id, 'name' => 'Ready', 'provider' => LlmConnection::PROVIDER_OPENAI,
            'model' => 'gpt-test', 'api_key' => 'provider-key-ready', 'key_hint' => 'eady',
            'status' => LlmConnection::STATUS_READY,
        ]);

        LlmSetting::query()->create(['user_id' => $owner->id, 'active_connection_id' => $connection->id]);
        $this->assertDatabaseHas('llm_settings', ['user_id' => $owner->id, 'active_connection_id' => $connection->id]);

        $this->expectException(LogicException::class);
        LlmSetting::query()->create(['user_id' => $foreign->id, 'active_connection_id' => $connection->id]);
    }

    public function test_consent_confirmation_and_audit_models_keep_closed_content_free_state(): void
    {
        $owner = User::factory()->create();
        $connection = LlmConnection::query()->create([
            'user_id' => $owner->id, 'name' => 'Ready', 'provider' => LlmConnection::PROVIDER_ANTHROPIC,
            'model' => 'claude-test', 'api_key' => 'provider-key-ready', 'key_hint' => 'eady',
            'status' => LlmConnection::STATUS_READY,
        ]);
        $item = Item::query()->create(['user_id' => $owner->id, 'title' => 'Private title']);
        $consent = LlmConsent::query()->create([
            'user_id' => $owner->id, 'scope' => LlmConsent::SCOPE_STORAGE_INBOX, 'granted_at' => now(),
        ]);
        $confirmation = LlmToolConfirmation::query()->create([
            'user_id' => $owner->id,
            'llm_connection_id' => $connection->id,
            'token_hash' => hash('sha256', 'opaque-token'),
            'proposal_hash' => hash('sha256', 'proposal'),
            'tool_name' => 'storage_triage_inbox_item',
            'source_type' => 'item',
            'source_id' => $item->id,
            'source_fingerprint' => hash('sha256', 'source'),
            'status' => LlmToolConfirmation::STATUS_PENDING,
            'expires_at' => now()->addMinutes(10),
        ]);
        $audit = LlmAuditEvent::query()->create([
            'user_id' => $owner->id,
            'llm_connection_id' => $connection->id,
            'event' => LlmAuditEvent::EVENT_DRAFT_ACCEPTED,
            'scope' => LlmConsent::SCOPE_STORAGE_INBOX,
            'outcome' => LlmAuditEvent::OUTCOME_SUCCEEDED,
            'occurred_at' => now(),
        ]);

        $this->assertTrue($consent->isGranted());
        $this->assertTrue($confirmation->isPending());
        $this->assertSame(
            ['id', 'user_id', 'llm_connection_id', 'event', 'scope', 'outcome', 'error_code', 'occurred_at', 'created_at', 'updated_at'],
            array_keys($audit->fresh()->getAttributes()),
        );
        $encoded = json_encode(DB::table('llm_tool_confirmations')->where('id', $confirmation->id)->first());
        $this->assertStringNotContainsString('Private title', $encoded);
        $this->assertStringNotContainsString('A generated rationale', $encoded);

        try {
            LlmToolConfirmation::query()->create([
                ...$confirmation->only([
                    'user_id', 'llm_connection_id', 'proposal_hash', 'tool_name', 'source_type', 'source_id',
                    'source_fingerprint', 'expires_at',
                ]),
                'token_hash' => hash('sha256', 'invalid-lifecycle-token'),
                'status' => LlmToolConfirmation::STATUS_APPLIED,
            ]);
            $this->fail('An applied confirmation without applied_at was accepted.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(LogicException::class);
        $audit->delete();
    }

    public function test_ai_migration_rolls_back_only_026_and_preserves_storage(): void
    {
        $owner = User::factory()->create();
        $item = Item::query()->create(['user_id' => $owner->id, 'title' => 'Preserved']);
        $migration = require database_path('migrations/2026_08_14_090000_create_ai_assistant_foundation.php');

        $migration->down();
        foreach (['llm_connections', 'llm_settings', 'llm_consents', 'llm_tool_confirmations', 'llm_audit_events'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertDatabaseHas('items', ['id' => $item->id, 'title' => 'Preserved']);

        $migration->up();
        $this->assertTrue(Schema::hasTable('llm_connections'));
        $this->assertDatabaseHas('items', ['id' => $item->id, 'title' => 'Preserved']);
    }
}
