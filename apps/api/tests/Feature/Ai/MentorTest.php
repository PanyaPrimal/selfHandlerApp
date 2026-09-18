<?php

namespace Tests\Feature\Ai;

use App\Jobs\ProcessMentorTurn;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\Item;
use App\Models\LlmConnection;
use App\Models\LlmSetting;
use App\Models\User;
use App\Services\Mentor\MentorService;
use App\Services\Mentor\PersonalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MentorTest extends TestCase
{
    use RefreshDatabase;

    public function test_voice_retry_returns_saved_transcript_without_a_second_paid_request(): void
    {
        $owner = $this->ready();
        Http::fake(['https://api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'Buy milk'])]);
        $input = ['operation_id' => (string) Str::uuid(), 'audio' => UploadedFile::fake()->create('voice.wav', 10, 'audio/wav')];
        $this->postJson('/api/mentor/transcribe', $input)->assertOk()->assertJsonPath('text', 'Buy milk');
        $this->postJson('/api/mentor/transcribe', $input)->assertOk()->assertJsonPath('text', 'Buy milk');
        Http::assertSentCount(1);
        $this->getJson('/api/mentor/turns')->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('mentor_turns', ['user_id' => $owner->id, 'status' => 'transcribed']);
    }

    public function test_missing_usage_retains_reservation_and_never_claims_zero_cost(): void
    {
        $this->ready();
        $response = $this->answer();
        unset($response['usage']);
        Http::fakeSequence()->push($response);
        $input = ['operation_id' => (string) Str::uuid(), 'question' => 'Hello'];
        $this->postJson('/api/mentor/turns', $input)->assertOk()->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.estimated_usd', null)->assertJsonPath('data.usage.reserved', MentorService::RESERVATION);
        $this->postJson('/api/mentor/turns', $input)->assertOk();
        Http::assertSentCount(1);
    }

    public function test_duplicate_worker_delivery_does_not_repeat_the_paid_call(): void
    {
        $this->ready();
        Queue::fake();
        Http::fakeSequence()->push($this->answer());
        $turn = $this->postJson('/api/mentor/turns', ['operation_id' => (string) Str::uuid(), 'question' => 'Hello'])
            ->assertOk()->assertJsonPath('data.status', 'pending')->json('data.id');
        Http::assertNothingSent();
        $service = app(MentorService::class);
        $service->processRound($turn);
        $service->processRound($turn);
        Http::assertSentCount(1);
        $this->assertDatabaseHas('mentor_turns', ['id' => $turn, 'status' => 'completed', 'context' => null]);
    }

    public function test_queued_request_rechecks_consent_before_transmitting_context(): void
    {
        $owner = $this->ready();
        Queue::fake();
        $turn = $this->postJson('/api/mentor/turns', ['operation_id' => (string) Str::uuid(), 'question' => 'Hello'])->json('data.id');
        DB::table('mentor_preferences')->where('user_id', $owner->id)->update(['enabled' => false]);
        (new ProcessMentorTurn($turn))->handle(app(MentorService::class));
        Http::assertNothingSent();
        $this->assertDatabaseHas('mentor_turns', ['id' => $turn, 'status' => 'failed', 'reserved_tokens' => 0]);
    }

    public function test_database_worker_completes_a_pending_turn_without_serializing_credentials(): void
    {
        $this->ready();
        config(['queue.default' => 'database']);
        Http::fakeSequence()->push($this->answer());
        $id = $this->postJson('/api/mentor/turns', ['operation_id' => (string) Str::uuid(), 'question' => 'Hello'])
            ->assertOk()->assertJsonPath('data.status', 'pending')->json('data.id');
        Http::assertNothingSent();
        $job = DB::table('jobs')->where('queue', 'mentor')->first();
        $this->assertNotNull($job);
        $this->assertStringNotContainsString('fixture-key', $job->payload);
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'mentor', '--once' => true])->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertDatabaseHas('mentor_turns', ['id' => $id, 'status' => 'completed']);
        $this->assertDatabaseCount('jobs', 0);
    }

    private function ready(string $provider = 'openai'): User
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $connection = LlmConnection::query()->create(['user_id' => $user->id, 'name' => 'Test',
            'provider' => $provider, 'model' => 'gpt-6-astra', 'api_key' => 'fixture-key-1234',
            'key_hint' => '1234', 'status' => 'ready']);
        LlmSetting::query()->create(['user_id' => $user->id, 'active_connection_id' => $connection->id]);
        $this->actingAs($user)->putJson('/api/mentor/settings', ['enabled' => true,
            'memory' => 'Prefer short answers', 'monthly_token_limit' => 1000000])->assertOk();

        return $user;
    }

    private function answer(string $name = 'finish', ?array $arguments = null): array
    {
        return ['status' => 'completed', 'output' => [['type' => 'function_call', 'name' => $name,
            'arguments' => json_encode($arguments ?? ['answer' => 'Ready', 'actions' => []])]],
            'usage' => ['input_tokens' => 200, 'output_tokens' => 100,
                'input_tokens_details' => ['cached_tokens' => 100], 'output_tokens_details' => ['reasoning_tokens' => 20]]];
    }

    public function test_retrieval_is_bounded_owner_scoped_and_never_includes_credentials(): void
    {
        $owner = $this->ready();
        Item::query()->create(['user_id' => $owner->id, 'title' => 'My private task']);
        Item::query()->create(['user_id' => User::factory()->create()->id, 'title' => 'Foreign private task']);
        Http::fakeSequence()->push($this->answer('read_records', ['dataset' => 'items', 'offset' => 0, 'limit' => 10]))
            ->push($this->answer());
        $this->postJson('/api/mentor/turns', ['operation_id' => (string) Str::uuid(), 'question' => 'What should I do?'])
            ->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.sources.0.matched', 1)
            ->assertJsonPath('data.usage.input', 400)->assertJsonPath('data.usage.output', 200);
        $requests = Http::recorded();
        $context = $requests[1][0]['input'][1]['content'];
        $this->assertStringContainsString('My private task', $context);
        $this->assertStringNotContainsString('Foreign private task', $context);
        $this->assertStringNotContainsString('fixture-key', $context);
        $this->assertArrayNotHasKey('llm_connections', app(PersonalContext::class)->catalogue());
        $this->actingAs(User::factory()->create())->getJson('/api/mentor/turns')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_proposals_require_confirmation_and_retries_never_duplicate_actions_or_api_calls(): void
    {
        $owner = $this->ready();
        Http::fakeSequence()->push($this->answer('finish', ['answer' => 'Create this task?', 'actions' => [[
            'kind' => 'capture_item', 'label' => 'Buy milk', 'payload_json' => json_encode(['title' => 'Buy milk']),
        ]]]));
        $input = ['operation_id' => (string) Str::uuid(), 'question' => 'Remember milk'];
        $turn = $this->postJson('/api/mentor/turns', $input)->assertOk()->json('data.id');
        $this->assertDatabaseCount('items', 0);
        $this->postJson('/api/mentor/turns', $input)->assertOk()->assertJsonPath('data.id', $turn);
        Http::assertSentCount(1);
        $url = "/api/mentor/turns/{$turn}/actions/0";
        $this->postJson($url, [])->assertUnprocessable();
        $this->actingAs(User::factory()->create())->postJson($url, ['confirm' => true])->assertNotFound();
        $this->actingAs($owner)->postJson($url, ['confirm' => true])->assertOk()->assertJsonPath('data.actions.0.status', 'applied');
        $this->postJson($url, ['confirm' => true])->assertOk();
        $this->assertDatabaseCount('items', 1);
        $this->assertDatabaseHas('items', ['user_id' => $owner->id, 'title' => 'Buy milk']);
    }

    public function test_stale_confirmation_does_not_write_and_token_budget_blocks_before_network(): void
    {
        $owner = $this->ready();
        Http::fakeSequence()->push($this->answer('finish', ['answer' => 'Create?', 'actions' => [[
            'kind' => 'capture_item', 'label' => 'Task', 'payload_json' => '{"title":"Task"}',
        ]]]));
        $turn = $this->postJson('/api/mentor/turns', ['operation_id' => (string) Str::uuid(), 'question' => 'Task'])
            ->assertOk()->json('data.id');
        $this->postJson('/api/storage/items', ['title' => 'Newer edit'])->assertCreated();
        $this->postJson("/api/mentor/turns/{$turn}/actions/0", ['confirm' => true])
            ->assertConflict()->assertJsonPath('code', 'ai_confirmation_stale');
        DB::table('mentor_preferences')->where('user_id', $owner->id)->update(['monthly_token_limit' => 1]);
        $this->postJson('/api/mentor/turns', ['operation_id' => (string) Str::uuid(), 'question' => 'Another'])
            ->assertStatus(429)->assertJsonPath('code', 'mentor_budget_exceeded');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('items', 1);
    }

    public function test_untrusted_tool_cannot_select_users_or_inject_ownership(): void
    {
        $this->ready();
        Http::fakeSequence()->push($this->answer('read_records', ['dataset' => 'users', 'offset' => 0, 'limit' => 10]))
            ->push($this->answer('finish', ['answer' => 'Task', 'actions' => [[
                'kind' => 'capture_item', 'label' => 'Bad', 'payload_json' => '{"title":"Bad","user_id":999}',
            ]]]));
        foreach (['Read users', 'Change owner'] as $question) {
            $this->postJson('/api/mentor/turns', ['operation_id' => (string) Str::uuid(), 'question' => $question])
                ->assertOk()->assertJsonPath('data.status', 'failed');
        }
        $this->assertDatabaseCount('items', 0);
        $this->assertEquals(400, DB::table('mentor_turns')->sum('input_tokens'));
    }

    public function test_anthropic_tools_work_with_the_saved_connection(): void
    {
        $this->ready('anthropic');
        Http::fakeSequence()->push(['stop_reason' => 'tool_use', 'content' => [['type' => 'tool_use',
            'name' => 'finish', 'input' => ['answer' => 'Hello', 'actions' => []]]],
            'usage' => ['input_tokens' => 100, 'cache_read_input_tokens' => 50, 'output_tokens' => 20]]);
        $this->postJson('/api/mentor/turns', ['operation_id' => (string) Str::uuid(), 'question' => 'Hello'])
            ->assertOk()->assertJsonPath('data.answer', 'Hello')->assertJsonPath('data.usage.input', 150);
        Http::assertSent(fn ($request) => $request->hasHeader('x-api-key', 'fixture-key-1234') && isset($request['tools'][0]['input_schema']));
    }

    public function test_all_supported_action_types_use_domain_validation_and_apply_once(): void
    {
        $owner = $this->ready();
        $account = FinanceAccount::factory()->create(['user_id' => $owner->id]);
        $category = FinanceCategory::factory()->create(['user_id' => $owner->id, 'direction' => 'expense']);
        $actions = [
            ['kind' => 'record_measurement', 'label' => 'Weight', 'payload_json' => json_encode([
                'metric' => 'body_mass', 'measured_on' => '2026-01-01', 'value' => 75000,
            ])],
            ['kind' => 'plan_block', 'label' => 'Walk', 'payload_json' => json_encode([
                'title' => 'Walk', 'block_date' => '2026-01-01', 'starts_at' => '10:00', 'ends_at' => '10:30',
            ])],
            ['kind' => 'record_transaction', 'label' => 'Food', 'payload_json' => json_encode([
                'kind' => 'expense', 'account_id' => $account->id, 'category_id' => $category->id,
                'amount' => '12.3456', 'occurred_on' => '2026-01-01',
            ])],
        ];
        Http::fakeSequence()->push($this->answer('finish', ['answer' => 'Review changes', 'actions' => $actions]));
        $turn = $this->postJson('/api/mentor/turns', ['operation_id' => (string) Str::uuid(), 'question' => 'Record my day'])
            ->assertOk()->json('data.id');
        foreach (array_keys($actions) as $index) {
            $url = "/api/mentor/turns/{$turn}/actions/{$index}";
            $this->postJson($url, ['confirm' => true])->assertOk()->assertJsonPath("data.actions.{$index}.status", 'applied');
            $this->postJson($url, ['confirm' => true])->assertOk();
        }
        $this->assertDatabaseHas('body_measurements', ['user_id' => $owner->id, 'value' => 75000]);
        $this->assertDatabaseHas('time_blocks', ['user_id' => $owner->id, 'title' => 'Walk']);
        $this->assertDatabaseCount('finance_transaction_groups', 1);
        $this->assertDatabaseHas('finance_ledger_entries', ['account_id' => $account->id, 'delta_amount' => '-12.3456']);
        Http::assertSentCount(1);
    }

    public function test_financial_action_cannot_reference_another_accounts_record(): void
    {
        $owner = $this->ready();
        $foreign = FinanceAccount::factory()->create();
        $category = FinanceCategory::factory()->create(['user_id' => $owner->id, 'direction' => 'expense']);
        Http::fakeSequence()->push($this->answer('finish', ['answer' => 'Review', 'actions' => [[
            'kind' => 'record_transaction', 'label' => 'Expense', 'payload_json' => json_encode([
                'kind' => 'expense', 'account_id' => $foreign->id, 'category_id' => $category->id,
                'amount' => '10.00', 'occurred_on' => '2026-01-01',
            ]),
        ]]]));
        $turn = $this->postJson('/api/mentor/turns', ['operation_id' => (string) Str::uuid(), 'question' => 'Expense'])->json('data.id');
        $this->postJson("/api/mentor/turns/{$turn}/actions/0", ['confirm' => true])->assertNotFound();
        $this->assertDatabaseCount('finance_transaction_groups', 0);
        $this->assertDatabaseCount('finance_ledger_entries', 0);
    }
}
