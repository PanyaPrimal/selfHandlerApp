<?php

namespace App\Services\Ai;

use App\Exceptions\AiAssistantException;
use App\Models\Item;
use App\Models\LlmToolConfirmation;
use App\Models\User;

class LlmToolAuthorizationService
{
    public function __construct(
        private readonly LlmToolRegistry $registry,
        private readonly StorageInboxTriageTool $storageTriage,
    ) {}

    /** @param array<string,mixed> $proposal */
    public function executeConfirmed(
        User $user,
        LlmToolConfirmation $confirmation,
        Item $item,
        array $proposal,
    ): Item {
        $definition = $this->registry->for($confirmation->tool_name);
        if (! $definition->writes || ! $definition->confirmationRequired
            || $confirmation->status !== LlmToolConfirmation::STATUS_PENDING
            || (int) $confirmation->user_id !== (int) $user->id) {
            throw AiAssistantException::confirmationRequired();
        }

        return match ($definition->name) {
            LlmToolRegistry::STORAGE_TRIAGE_TOOL => $this->storageTriage->execute($user, $item, $proposal),
            default => throw AiAssistantException::toolNotAllowed(),
        };
    }
}
