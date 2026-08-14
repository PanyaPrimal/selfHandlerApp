<?php

namespace App\Data\Ai;

final readonly class LlmToolCall
{
    /** @param array<string,mixed> $arguments */
    public function __construct(public string $name, public array $arguments) {}
}
