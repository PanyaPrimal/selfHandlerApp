<?php

namespace App\Data\Ai;

final readonly class LlmToolDefinition
{
    /** @param array<string,mixed> $schema */
    public function __construct(
        public string $name,
        public string $description,
        public array $schema,
        public bool $writes,
        public bool $confirmationRequired,
    ) {}
}
