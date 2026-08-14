<?php

namespace App\Services\Portability;

use App\Exceptions\PortabilityException;
use App\Models\User;

class RestoreTokenService
{
    public function issue(User $user, string $archiveSha256): array
    {
        $expires = now('UTC')->addSeconds((int) config('portability.token_ttl_seconds'));
        $payload = $this->encode(json_encode([
            'v' => PortabilitySchemaV1::VERSION, 'uid' => (int) $user->id,
            'sha256' => $archiveSha256, 'exp' => $expires->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
        $signature = $this->encode(hash_hmac('sha256', $payload, $this->key(), true));

        return ['token' => $payload.'.'.$signature, 'expires_at' => $expires->toIso8601String()];
    }

    public function verify(string $token, User $user, string $archiveSha256): void
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || ! hash_equals($this->encode(hash_hmac('sha256', $parts[0], $this->key(), true)), $parts[1])) {
            throw new PortabilityException('restore_token_invalid');
        }
        $json = $this->decode($parts[0]);
        try {
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PortabilityException('restore_token_invalid');
        }
        if (! is_array($payload) || ($payload['v'] ?? null) !== PortabilitySchemaV1::VERSION
            || ($payload['uid'] ?? null) !== (int) $user->id
            || ! is_string($payload['sha256'] ?? null) || ! hash_equals($archiveSha256, $payload['sha256'])
            || ! is_int($payload['exp'] ?? null) || $payload['exp'] <= now('UTC')->getTimestamp()) {
            throw new PortabilityException('restore_token_invalid');
        }
    }

    private function key(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if (is_string($decoded)) {
                return $decoded;
            }
        }
        if ($key === '') {
            throw new PortabilityException('restore_token_unavailable');
        }

        return $key;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (! is_string($decoded)) {
            throw new PortabilityException('restore_token_invalid');
        }

        return $decoded;
    }
}
