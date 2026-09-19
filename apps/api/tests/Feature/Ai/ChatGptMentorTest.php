<?php

namespace Tests\Feature\Ai;

use App\Jobs\ProcessMentorTurn;
use App\Models\User;
use App\Services\Mentor\MentorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatGptMentorTest extends TestCase
{
    use RefreshDatabase;

    private function configureBridge(): void
    {
        config(['chatgpt.url' => 'http://bridge.test', 'chatgpt.token' => str_repeat('s', 40)]);
        Http::preventStrayRequests();
    }

    public function test_login_and_status_are_scoped_to_authenticated_user_and_never_accept_an_owner(): void
    {
        $this->configureBridge();
        Http::fake(['http://bridge.test/*' => Http::response(['connected' => false])]);
        $this->getJson('/api/mentor/chatgpt')->assertUnauthorized();
        $one = User::factory()->create();
        $two = User::factory()->create();
        $this->actingAs($one)->getJson('/api/mentor/chatgpt')->assertOk();
        $this->actingAs($two)->getJson('/api/mentor/chatgpt')->assertOk();
        $this->postJson('/api/mentor/chatgpt/login', ['user_id' => $one->id])->assertUnprocessable();
        $urls = Http::recorded()->map(fn ($pair) => $pair[0]->url())->all();
        $this->assertCount(2, $urls);
        $this->assertNotSame($urls[0], $urls[1]);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.str_repeat('s', 40)));
    }

    public function test_chatgpt_completes_a_mentor_turn_without_api_credentials_and_retries_do_not_repeat_it(): void
    {
        $this->configureBridge();
        $owner = User::factory()->create();
        Http::fake([
            'http://bridge.test/*/models' => Http::response(['models' => [['id' => 'gpt-6-astra']]]),
            'http://bridge.test/*/call' => Http::response(['valid' => true, 'name' => 'finish',
                'arguments' => ['answer' => 'Ready through subscription', 'actions' => []],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'cached_tokens' => 0, 'cache_write_tokens' => 0, 'reasoning_tokens' => 0]]),
        ]);
        $this->actingAs($owner)->putJson('/api/mentor/settings', ['enabled' => true, 'memory' => '',
            'auth_mode' => 'chatgpt', 'chatgpt_model' => 'gpt-6-astra', 'monthly_token_limit' => 1000000])->assertOk();
        $input = ['operation_id' => (string) Str::uuid(), 'question' => 'Hello'];
        $this->postJson('/api/mentor/turns', $input)->assertOk()->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.answer', 'Ready through subscription')->assertJsonPath('data.provider', 'chatgpt');
        $this->postJson('/api/mentor/turns', $input)->assertOk();
        Http::assertSentCount(2);
        $this->assertDatabaseHas('mentor_turns', ['user_id' => $owner->id, 'connection_id' => null, 'estimated_usd' => 0, 'reserved_tokens' => 0]);
        $this->assertDatabaseCount('llm_connections', 0);
    }

    public function test_logout_disables_queued_mentor_and_subscription_never_calls_paid_transcription(): void
    {
        $this->configureBridge();
        $owner = User::factory()->create();
        $this->actingAs($owner);
        DB::table('mentor_preferences')->insert(['user_id' => $owner->id, 'enabled' => true, 'auth_mode' => 'chatgpt', 'chatgpt_model' => 'gpt-6-astra']);
        Queue::fake();
        Http::fake(['http://bridge.test/*/logout' => Http::response(['connected' => false])]);
        $id = $this->postJson('/api/mentor/turns', ['operation_id' => (string) Str::uuid(), 'question' => 'Hello'])->assertOk()->json('data.id');
        $this->postJson('/api/mentor/transcribe', ['operation_id' => (string) Str::uuid(), 'audio' => UploadedFile::fake()->create('voice.wav', 10, 'audio/wav')])->assertConflict();
        Http::assertNothingSent();
        $this->postJson('/api/mentor/chatgpt/logout')->assertOk();
        (new ProcessMentorTurn($id))->handle(app(MentorService::class));
        Http::assertSentCount(1);
        $this->assertDatabaseHas('mentor_turns', ['id' => $id, 'status' => 'failed']);
    }

    public function test_unavailable_model_cannot_be_enabled(): void
    {
        $this->configureBridge();
        Http::fake(['http://bridge.test/*/models' => Http::response(['models' => [['id' => 'allowed']]])]);
        $this->actingAs(User::factory()->create())->putJson('/api/mentor/settings', [
            'enabled' => true, 'memory' => '', 'auth_mode' => 'chatgpt', 'chatgpt_model' => 'other', 'monthly_token_limit' => 1000000,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('mentor_preferences', 0);
    }

    public function test_explicit_null_model_cannot_reuse_validation_of_the_previous_selection(): void
    {
        $this->configureBridge();
        $owner = User::factory()->create();
        DB::table('mentor_preferences')->insert(['user_id' => $owner->id, 'enabled' => true, 'auth_mode' => 'chatgpt', 'chatgpt_model' => 'gpt-6-astra']);
        Http::fake(['http://bridge.test/*/models' => Http::response(['models' => [['id' => 'gpt-6-astra']]])]);
        $this->actingAs($owner)->putJson('/api/mentor/settings', [
            'enabled' => true, 'memory' => '', 'auth_mode' => 'chatgpt', 'chatgpt_model' => null, 'monthly_token_limit' => 1000000,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('mentor_preferences', ['user_id' => $owner->id, 'chatgpt_model' => 'gpt-6-astra']);
    }
}
