<?php

namespace App\Services\Ai\Providers;

use App\Contracts\LlmProvider;
use App\Data\Ai\LlmToolCall;
use App\Data\Ai\LlmToolDefinition;
use App\Exceptions\AiAssistantException;
use App\Models\LlmConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AnthropicLlmProvider implements LlmProvider
{
    public function provider(): string
    {
        return LlmConnection::PROVIDER_ANTHROPIC;
    }

    public function test(LlmConnection $connection): void
    {
        $response = $this->send($connection, [
            'model' => $connection->model,
            'max_tokens' => 16,
            'system' => 'Return the requested probe text only. Do not use tools or external context.',
            'messages' => [['role' => 'user', 'content' => 'Reply with exactly SELFHANDLER_OK.']],
        ]);
        $this->assertSuccessful($response);
        $content = $response->json('content');
        $text = is_array($content)
            ? collect($content)->firstWhere('type', 'text')['text'] ?? null
            : null;
        if ($response->json('stop_reason') !== 'end_turn' || trim((string) $text) !== 'SELFHANDLER_OK') {
            throw AiAssistantException::invalidResponse();
        }
    }

    public function propose(
        LlmConnection $connection,
        string $system,
        array $context,
        LlmToolDefinition $tool,
    ): LlmToolCall {
        $response = $this->send($connection, [
            'model' => $connection->model,
            'max_tokens' => $connection->parameters['max_output_tokens'],
            'system' => $system,
            'messages' => [[
                'role' => 'user',
                'content' => json_encode(['context' => $context], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]],
            'tools' => [[
                'name' => $tool->name,
                'description' => $tool->description,
                'input_schema' => $tool->schema,
                'strict' => true,
            ]],
            'tool_choice' => [
                'type' => 'tool',
                'name' => $tool->name,
                'disable_parallel_tool_use' => true,
            ],
        ]);
        $this->assertSuccessful($response);
        $stop = $response->json('stop_reason');
        if ($stop === 'refusal') {
            throw AiAssistantException::providerRefused();
        }
        if ($stop === 'max_tokens') {
            throw AiAssistantException::unsupportedCapability();
        }
        $calls = collect($response->json('content', []))
            ->filter(fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) === 'tool_use')
            ->values();
        if ($stop !== 'tool_use' || $calls->count() !== 1) {
            throw AiAssistantException::invalidResponse();
        }
        $call = $calls->first();
        if (! is_string($call['name'] ?? null) || ! is_array($call['input'] ?? null)) {
            throw AiAssistantException::invalidResponse();
        }

        return new LlmToolCall($call['name'], $call['input']);
    }

    /** @param array<string,mixed> $payload */
    private function send(LlmConnection $connection, array $payload): Response
    {
        try {
            return $this->client($connection)->post((string) config('ai.endpoints.anthropic'), $payload);
        } catch (ConnectionException) {
            throw AiAssistantException::providerTimeout();
        }
    }

    private function client(LlmConnection $connection): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->withoutRedirecting()
            ->connectTimeout((int) config('ai.connect_timeout_seconds', 5))
            ->timeout((int) config('ai.timeout_seconds', 20))
            ->withHeaders([
                'x-api-key' => $connection->api_key,
                'anthropic-version' => (string) config('ai.anthropic_version', '2023-06-01'),
            ]);
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }
        throw match ($response->status()) {
            401, 403 => AiAssistantException::credentialsInvalid(),
            429 => AiAssistantException::providerRateLimited(),
            400, 404, 422 => AiAssistantException::unsupportedCapability(),
            default => AiAssistantException::providerUnavailable(),
        };
    }
}
