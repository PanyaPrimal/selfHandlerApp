<?php

namespace App\Services\Ai;

use App\Data\Ai\LlmToolCall;
use App\Exceptions\AiAssistantException;
use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class InboxTriageProposalValidator
{
    /** @return array<string,mixed> */
    public function validate(User $user, LlmToolCall $call): array
    {
        if ($call->name !== LlmToolRegistry::STORAGE_TRIAGE_TOOL) {
            throw AiAssistantException::toolNotAllowed();
        }
        $allowed = ['type', 'project_id', 'tags', 'priority', 'due_on', 'rationale'];
        if (array_diff(array_keys($call->arguments), $allowed) !== []
            || array_diff($allowed, array_keys($call->arguments)) !== []) {
            throw AiAssistantException::invalidResponse();
        }
        $validator = Validator::make($call->arguments, [
            'type' => ['required', 'string', Rule::in(Item::TYPES)],
            'project_id' => ['present', 'nullable', 'integer', 'min:1'],
            'tags' => ['present', 'array', 'max:5'],
            'tags.*' => ['required', 'string', 'max:64', 'distinct'],
            'priority' => ['present', 'nullable', 'string', Rule::in(Item::PRIORITIES)],
            'due_on' => ['present', 'nullable', 'date_format:Y-m-d'],
            'rationale' => ['required', 'string', 'max:300'],
        ]);
        if ($validator->fails()) {
            throw AiAssistantException::invalidResponse();
        }
        $data = $validator->validated();
        if (trim($data['rationale']) === '') {
            throw AiAssistantException::invalidResponse();
        }
        if ($data['project_id'] !== null && ! Project::query()->ownedBy($user)
            ->whereKey($data['project_id'])->where('is_archived', false)->exists()) {
            throw AiAssistantException::invalidResponse();
        }
        $tags = array_values(array_unique(array_filter(
            array_map(static fn (string $tag): string => trim($tag), $data['tags']),
            static fn (string $tag): bool => $tag !== '',
        )));
        if (count($tags) !== count($data['tags'])) {
            throw AiAssistantException::invalidResponse();
        }

        return [
            'type' => $data['type'],
            'project_id' => $data['project_id'],
            'tags' => $tags,
            'priority' => $data['priority'],
            'due_on' => $data['due_on'],
            'rationale' => trim($data['rationale']),
        ];
    }
}
