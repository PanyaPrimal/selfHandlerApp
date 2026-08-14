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
use JsonException;

class OpenAiLlmProvider implements LlmProvider
{
    public function provider(): string
    {
        return LlmConnection::PROVIDER_OPENAI;
    }

    public function test(LlmConnection $connection): void
    {
        $response = $this->send($connection, [
            'model' => $connection->model,
            'store' => false,
            'max_output_tokens' => 16,
            'input' => [
                ['role' => 'system', 'content' => 'Return the requested probe text only. Do not use tools or external context.'],
                ['role' => 'user', 'content' => 'Reply with exactly SELFHANDLER_OK.'],
            ],
        ]);
        $this->assertSuccessful($response);
        $text = collect($response->json('output', []))
            ->flatMap(fn (mixed $item): array => is_array($item) && is_array($item['content'] ?? null) ? $item['content'] : [])
            ->firstWhere('type', 'output_text')['text'] ?? null;
        if ($response->json('status') !== 'completed' || trim((string) $text) !== 'SELFHANDLER_OK') {
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
            'store' => false,
            'max_output_tokens' => $connection->parameters['max_output_tokens'],
            'input' => [
                ['role' => 'system', 'content' => $system],
                [
                    'role' => 'user',
                    'content' => json_encode(['context' => $context], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
            'tools' => [[
                'type' => 'function',
                'name' => $tool->name,
                'description' => $tool->description,
                'parameters' => $tool->schema,
                'strict' => true,
            ]],
            'tool_choice' => ['type' => 'function', 'name' => $tool->name],
            'parallel_tool_calls' => false,
        ]);
        $this->assertSuccessful($response);
        if ($response->json('status') === 'incomplete') {
            throw AiAssistantException::unsupportedCapability();
        }
        $refused = collect($response->json('output', []))
            ->flatMap(fn (mixed $item): array => is_array($item) && is_array($item['content'] ?? null) ? $item['content'] : [])
            ->contains(fn (mixed $content): bool => is_array($content) && ($content['type'] ?? null) === 'refusal');
        if ($refused) {
            throw AiAssistantException::providerRefused();
        }
        $calls = collect($response->json('output', []))
            ->filter(fn (mixed $item): bool => is_array($item) && ($item['type'] ?? null) === 'function_call')
            ->values();
        if ($response->json('status') !== 'completed' || $calls->count() !== 1) {
            throw AiAssistantException::invalidResponse();
        }
        $call = $calls->first();
        try {
            $arguments = json_decode((string) ($call['arguments'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw AiAssistantException::invalidResponse();
        }
        if (! is_string($call['name'] ?? null) || ! is_array($arguments)) {
            throw AiAssistantException::invalidResponse();
        }

        return new LlmToolCall($call['name'], $arguments);
    }

    /** @param array<string,mixed> $payload */
    private function send(LlmConnection $connection, array $payload): Response
    {
        try {
            return $this->client($connection)->post((string) config('ai.endpoints.openai'), $payload);
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
            ->withToken($connection->api_key);
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
