<?php

namespace App\Services\Ai;

use App\Data\Ai\LlmToolDefinition;
use App\Exceptions\AiAssistantException;

class LlmToolRegistry
{
    public const STORAGE_TRIAGE_TOOL = 'storage_triage_inbox_item';

    public function for(string $name): LlmToolDefinition
    {
        if ($name !== self::STORAGE_TRIAGE_TOOL) {
            throw AiAssistantException::toolNotAllowed();
        }

        return new LlmToolDefinition(
            name: self::STORAGE_TRIAGE_TOOL,
            description: 'Propose triage fields for exactly the selected SelfHandler Storage Inbox item. Do not act on any other item.',
            schema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['task', 'idea', 'purchase']],
                    'project_id' => ['type' => ['integer', 'null']],
                    'tags' => [
                        'type' => 'array', 'maxItems' => 5, 'uniqueItems' => true,
                        'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                    ],
                    'priority' => ['type' => ['string', 'null'], 'enum' => ['low', 'normal', 'high', null]],
                    'due_on' => ['type' => ['string', 'null'], 'format' => 'date'],
                    'rationale' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
                ],
                'required' => ['type', 'project_id', 'tags', 'priority', 'due_on', 'rationale'],
            ],
            writes: true,
            confirmationRequired: true,
        );
    }
}
