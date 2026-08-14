<?php

namespace App\Contracts;

use App\Data\Ai\LlmToolCall;
use App\Data\Ai\LlmToolDefinition;
use App\Models\LlmConnection;

interface LlmProvider
{
    public function provider(): string;

    public function test(LlmConnection $connection): void;

    /** @param array<string,mixed> $context */
    public function propose(
        LlmConnection $connection,
        string $system,
        array $context,
        LlmToolDefinition $tool,
    ): LlmToolCall;
}
