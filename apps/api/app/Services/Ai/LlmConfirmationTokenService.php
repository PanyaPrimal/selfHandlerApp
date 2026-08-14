<?php

namespace App\Services\Ai;

use App\Exceptions\AiAssistantException;
use App\Models\Item;
use App\Models\LlmConnection;
use App\Models\LlmToolConfirmation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

class LlmConfirmationTokenService
{
    /** @param array<string,mixed> $proposal
     * @return array{token:string,confirmation:LlmToolConfirmation,expires_at:CarbonImmutable}
     */
    public function issue(User $user, LlmConnection $connection, Item $item, string $tool, array $proposal): array
    {
        $expiresAt = CarbonImmutable::now()->addMinutes((int) config('ai.confirmation_ttl_minutes', 10));
        $sourceFingerprint = $this->sourceFingerprint($item);
        $proposalHash = $this->proposalHash($proposal);
        $payload = [
            'version' => 1,
            'nonce' => bin2hex(random_bytes(16)),
            'user_id' => $user->id,
            'connection_id' => $connection->id,
            'tool' => $tool,
            'source_type' => 'item',
            'source_id' => $item->id,
            'source_fingerprint' => $sourceFingerprint,
            'proposal_hash' => $proposalHash,
            'proposal' => $proposal,
            'expires_at' => $expiresAt->getTimestamp(),
        ];
        $token = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $confirmation = LlmToolConfirmation::query()->create([
            'user_id' => $user->id,
            'llm_connection_id' => $connection->id,
            'token_hash' => hash('sha256', $token),
            'proposal_hash' => $proposalHash,
            'tool_name' => $tool,
            'source_type' => 'item',
            'source_id' => $item->id,
            'source_fingerprint' => $sourceFingerprint,
            'status' => LlmToolConfirmation::STATUS_PENDING,
            'expires_at' => $expiresAt,
        ]);

        return ['token' => $token, 'confirmation' => $confirmation, 'expires_at' => $expiresAt];
    }

    /** @return array<string,mixed> */
    public function decode(string $token): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw AiAssistantException::confirmationStale();
        }
        if (! is_array($payload)
            || ($payload['version'] ?? null) !== 1
            || ! is_array($payload['proposal'] ?? null)
            || ! is_int($payload['expires_at'] ?? null)) {
            throw AiAssistantException::confirmationStale();
        }
        if ($payload['expires_at'] < now()->getTimestamp()) {
            throw AiAssistantException::confirmationExpired();
        }

        return $payload;
    }

    /** @param array<string,mixed> $proposal */
    public function proposalHash(array $proposal): string
    {
        ksort($proposal);

        return hash('sha256', json_encode($proposal, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function sourceFingerprint(Item $item): string
    {
        $item->loadMissing('tags');
        $payload = [
            'id' => $item->id,
            'user_id' => $item->user_id,
            'type' => $item->type,
            'title' => $item->title,
            'description' => $item->description,
            'status' => $item->status,
            'priority' => $item->priority,
            'due_on' => $item->due_on?->format('Y-m-d'),
            'project_id' => $item->project_id,
            'tags' => $item->tags->pluck('name')->sort()->values()->all(),
            'updated_at' => $item->updated_at?->toJSON(),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
