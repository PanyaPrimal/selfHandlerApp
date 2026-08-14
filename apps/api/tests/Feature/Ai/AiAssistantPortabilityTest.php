<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Services\Portability\PortabilitySchemaV1;
use App\Services\Portability\RestoreEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AiAssistantPortabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_ai_table_is_explicitly_excluded_and_makes_restore_target_non_empty(): void
    {
        $tables = ['llm_connections', 'llm_settings', 'llm_consents', 'llm_tool_confirmations', 'llm_audit_events'];
        foreach ($tables as $table) {
            $this->assertContains($table, PortabilitySchemaV1::excludedOwnedTables());
        }

        foreach ($tables as $table) {
            $user = User::factory()->create();
            $connectionId = null;
            if ($table !== 'llm_consents') {
                $connectionId = DB::table('llm_connections')->insertGetId([
                    'user_id' => $user->id, 'name' => 'Fixture', 'provider' => 'openai', 'model' => 'gpt-test',
                    'api_key' => encrypt('fixture-key'), 'key_hint' => '-key',
                    'parameters' => json_encode(['max_output_tokens' => 512]), 'status' => 'ready',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            match ($table) {
                'llm_connections' => null,
                'llm_settings' => DB::table($table)->insert(['user_id' => $user->id, 'active_connection_id' => $connectionId, 'created_at' => now(), 'updated_at' => now()]),
                'llm_consents' => DB::table($table)->insert(['user_id' => $user->id, 'scope' => 'storage_inbox', 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]),
                'llm_tool_confirmations' => DB::table($table)->insert([
                    'user_id' => $user->id, 'llm_connection_id' => $connectionId,
                    'token_hash' => hash('sha256', 'token-'.$user->id), 'proposal_hash' => hash('sha256', 'proposal'),
                    'tool_name' => 'storage_triage_inbox_item', 'source_type' => 'item', 'source_id' => 1,
                    'source_fingerprint' => hash('sha256', 'source'), 'status' => 'pending',
                    'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now(),
                ]),
                'llm_audit_events' => DB::table($table)->insert([
                    'user_id' => $user->id, 'llm_connection_id' => $connectionId, 'event' => 'connection_created',
                    'outcome' => 'succeeded', 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]),
            };

            $this->assertFalse(app(RestoreEligibilityService::class)->isEmpty($user), $table.' must make target ineligible.');
        }
    }
}
