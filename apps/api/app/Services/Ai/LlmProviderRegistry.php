<?php

namespace App\Services\Ai;

use App\Contracts\LlmProvider;
use App\Exceptions\AiAssistantException;
use App\Services\Ai\Providers\AnthropicLlmProvider;
use App\Services\Ai\Providers\OpenAiLlmProvider;

class LlmProviderRegistry
{
    public function __construct(
        private readonly AnthropicLlmProvider $anthropic,
        private readonly OpenAiLlmProvider $openAi,
    ) {}

    public function for(string $provider): LlmProvider
    {
        return match ($provider) {
            $this->anthropic->provider() => $this->anthropic,
            $this->openAi->provider() => $this->openAi,
            default => throw AiAssistantException::unsupportedCapability(),
        };
    }
}
