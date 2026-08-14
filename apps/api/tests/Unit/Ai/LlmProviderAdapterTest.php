<?php

namespace Tests\Unit\Ai;

use App\Data\Ai\LlmToolDefinition;
use App\Exceptions\AiAssistantException;
use App\Models\LlmConnection;
use App\Services\Ai\LlmProviderRegistry;
use App\Services\Ai\Providers\AnthropicLlmProvider;
use App\Services\Ai\Providers\OpenAiLlmProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LlmProviderAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_anthropic_probe_and_strict_tool_use_fixed_endpoint_and_minimal_shape(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence('https://api.anthropic.com/v1/messages')
            ->push(['content' => [['type' => 'text', 'text' => 'SELFHANDLER_OK']], 'stop_reason' => 'end_turn'])
            ->push([
                'content' => [[
                    'type' => 'tool_use', 'id' => 'toolu_test', 'name' => 'storage_triage_inbox_item',
                    'input' => $this->proposal(),
                ]],
                'stop_reason' => 'tool_use',
            ]);
        $provider = app(AnthropicLlmProvider::class);
        $connection = $this->connection(LlmConnection::PROVIDER_ANTHROPIC);

        $provider->test($connection);
        $call = $provider->propose($connection, 'System boundary', ['item' => ['title' => 'Capture']], $this->tool());

        $this->assertSame('storage_triage_inbox_item', $call->name);
        $this->assertSame($this->proposal(), $call->arguments);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'fixture-provider-key-1234')
            && isset($request['tools'], $request['tool_choice'])
            && $request['tools'][0]['strict'] === true
            && $request['tool_choice']['name'] === 'storage_triage_inbox_item'
            && $request['tool_choice']['disable_parallel_tool_use'] === true
            && ! str_contains(json_encode($request->data()), 'finance')
            && ! str_contains(json_encode($request->data()), 'health'));
    }

    public function test_openai_probe_and_strict_function_use_fixed_endpoint_and_store_false(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence('https://api.openai.com/v1/responses')
            ->push(['status' => 'completed', 'output' => [[
                'type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'SELFHANDLER_OK']],
            ]]])
            ->push(['status' => 'completed', 'output' => [[
                'type' => 'function_call', 'call_id' => 'call_test', 'name' => 'storage_triage_inbox_item',
                'arguments' => json_encode($this->proposal()),
            ]]]);
        $provider = app(OpenAiLlmProvider::class);
        $connection = $this->connection(LlmConnection::PROVIDER_OPENAI);

        $provider->test($connection);
        $call = $provider->propose($connection, 'System boundary', ['item' => ['title' => 'Capture']], $this->tool());

        $this->assertSame($this->proposal(), $call->arguments);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.openai.com/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer fixture-provider-key-1234')
            && $request['store'] === false
            && isset($request['tools'], $request['tool_choice'], $request['parallel_tool_calls'])
            && $request['tools'][0]['strict'] === true
            && $request['tool_choice']['name'] === 'storage_triage_inbox_item'
            && $request['parallel_tool_calls'] === false);
    }

    public function test_adapters_map_credentials_rate_limit_refusal_and_invalid_shape_to_closed_errors(): void
    {
        $connection = $this->connection(LlmConnection::PROVIDER_ANTHROPIC);
        $provider = app(AnthropicLlmProvider::class);
        Http::fakeSequence('https://api.anthropic.com/v1/messages')
            ->push(['error' => ['message' => 'secret upstream detail']], 401)
            ->push(['error' => ['message' => 'secret upstream detail']], 429)
            ->push(['error' => ['message' => 'secret upstream detail']], 503)
            ->push([
                'content' => [['type' => 'text', 'text' => 'refusal text']], 'stop_reason' => 'refusal',
            ]);
        foreach ([401 => 'ai_credentials_invalid', 429 => 'ai_provider_rate_limited', 503 => 'ai_provider_unavailable'] as $status => $code) {
            try {
                $provider->test($connection);
                $this->fail('Expected a closed provider exception.');
            } catch (AiAssistantException $exception) {
                $this->assertSame($code, $exception->errorCode);
                $this->assertStringNotContainsString('secret upstream detail', $exception->getMessage());
            }
        }
        $this->expectExceptionObject(AiAssistantException::providerRefused());
        $provider->propose($connection, 'System', [], $this->tool());
    }

    public function test_registry_resolves_two_known_providers_and_denies_unknown(): void
    {
        $registry = app(LlmProviderRegistry::class);
        $this->assertInstanceOf(AnthropicLlmProvider::class, $registry->for(LlmConnection::PROVIDER_ANTHROPIC));
        $this->assertInstanceOf(OpenAiLlmProvider::class, $registry->for(LlmConnection::PROVIDER_OPENAI));

        $this->expectException(AiAssistantException::class);
        $registry->for('custom');
    }

    public function test_both_adapters_map_http_and_connection_failures_to_redacted_closed_codes(): void
    {
        Http::preventStrayRequests();
        $responses = Http::fakeSequence('*');
        foreach ([401, 403, 429, 400, 503] as $status) {
            $responses->push(['error' => ['message' => 'upstream-secret-body']], $status);
        }
        $responses->pushFailedConnection('upstream-private-timeout');
        foreach ([401, 403, 429, 400, 503] as $status) {
            $responses->push(['error' => ['message' => 'upstream-secret-body']], $status);
        }
        $responses->pushFailedConnection('upstream-private-timeout');
        foreach ([
            [app(AnthropicLlmProvider::class), $this->connection('anthropic')],
            [app(OpenAiLlmProvider::class), $this->connection('openai')],
        ] as [$provider, $connection]) {
            foreach ([
                401 => 'ai_credentials_invalid',
                403 => 'ai_credentials_invalid',
                429 => 'ai_provider_rate_limited',
                400 => 'ai_provider_unsupported_capability',
                503 => 'ai_provider_unavailable',
            ] as $status => $code) {
                $this->assertAiCode(fn () => $provider->test($connection), $code, 'upstream-secret-body');
            }

            $this->assertAiCode(fn () => $provider->test($connection), 'ai_provider_timeout', 'upstream-private-timeout');
        }
    }

    public function test_anthropic_rejects_truncation_invalid_and_multiple_tool_calls(): void
    {
        $provider = app(AnthropicLlmProvider::class);
        $connection = $this->connection('anthropic');
        $cases = [
            [['content' => [], 'stop_reason' => 'max_tokens'], 'ai_provider_unsupported_capability'],
            [['content' => [['type' => 'text', 'text' => 'not a call']], 'stop_reason' => 'end_turn'], 'ai_provider_invalid_response'],
            [[
                'content' => [
                    ['type' => 'tool_use', 'name' => 'storage_triage_inbox_item', 'input' => $this->proposal()],
                    ['type' => 'tool_use', 'name' => 'storage_triage_inbox_item', 'input' => $this->proposal()],
                ],
                'stop_reason' => 'tool_use',
            ], 'ai_provider_invalid_response'],
        ];
        $responses = Http::fakeSequence('https://api.anthropic.com/v1/messages');
        foreach ($cases as [$response]) {
            $responses->push($response);
        }
        foreach ($cases as [$response, $code]) {
            $this->assertAiCode(
                fn () => $provider->propose($connection, 'System', [], $this->tool()),
                $code,
            );
        }
    }

    public function test_openai_rejects_refusal_truncation_invalid_json_and_multiple_calls(): void
    {
        $provider = app(OpenAiLlmProvider::class);
        $connection = $this->connection('openai');
        $call = [
            'type' => 'function_call',
            'name' => 'storage_triage_inbox_item',
            'arguments' => json_encode($this->proposal()),
        ];
        $cases = [
            [['status' => 'completed', 'output' => [[
                'type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'private refusal']],
            ]]], 'ai_provider_refused'],
            [['status' => 'incomplete', 'output' => []], 'ai_provider_unsupported_capability'],
            [['status' => 'completed', 'output' => [[...$call, 'arguments' => '{invalid']]], 'ai_provider_invalid_response'],
            [['status' => 'completed', 'output' => [$call, $call]], 'ai_provider_invalid_response'],
        ];
        $responses = Http::fakeSequence('https://api.openai.com/v1/responses');
        foreach ($cases as [$response]) {
            $responses->push($response);
        }
        foreach ($cases as [$response, $code]) {
            $this->assertAiCode(
                fn () => $provider->propose($connection, 'System', [], $this->tool()),
                $code,
                'private refusal',
            );
        }
    }

    private function assertAiCode(callable $callback, string $code, string $redacted = ''): void
    {
        try {
            $callback();
            $this->fail('Expected a closed AI provider exception.');
        } catch (AiAssistantException $exception) {
            $this->assertSame($code, $exception->errorCode);
            if ($redacted !== '') {
                $this->assertStringNotContainsString($redacted, $exception->getMessage());
            }
        }
    }

    private function connection(string $provider): LlmConnection
    {
        return new LlmConnection([
            'provider' => $provider,
            'model' => $provider === LlmConnection::PROVIDER_OPENAI ? 'gpt-test' : 'claude-test',
            'api_key' => 'fixture-provider-key-1234',
            'parameters' => ['max_output_tokens' => 512],
        ]);
    }

    private function tool(): LlmToolDefinition
    {
        return new LlmToolDefinition(
            name: 'storage_triage_inbox_item',
            description: 'Propose triage for exactly one selected Inbox item.',
            schema: [
                'type' => 'object',
                'properties' => ['type' => ['type' => 'string']],
                'required' => ['type'],
                'additionalProperties' => false,
            ],
            writes: true,
            confirmationRequired: true,
        );
    }

    /** @return array<string,mixed> */
    private function proposal(): array
    {
        return [
            'type' => 'task', 'project_id' => null, 'tags' => ['focus'], 'priority' => 'high',
            'due_on' => '2026-08-15', 'rationale' => 'A bounded explanation.',
        ];
    }
}
