<?php

namespace App\Services\Integrations;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GoogleOAuthState
{
    private const TTL_SECONDS = 600;

    /** @return array{state:string,expires_at:CarbonImmutable} */
    public function issue(User $user): array
    {
        $state = Str::random(64);
        $expiresAt = CarbonImmutable::now()->addSeconds(self::TTL_SECONDS);
        Cache::put($this->key($state), ['user_id' => $user->id], $expiresAt);

        return ['state' => $state, 'expires_at' => $expiresAt];
    }

    public function consume(string $state): User
    {
        if (strlen($state) < 43 || strlen($state) > 512) {
            $this->invalid();
        }

        $key = $this->key($state);
        $payload = Cache::lock($key.':consume', 5)->block(1, static fn () => Cache::pull($key));
        $userId = is_array($payload) ? $payload['user_id'] ?? null : null;
        $user = is_int($userId) ? User::query()->find($userId) : null;
        if (! $user) {
            $this->invalid();
        }

        return $user;
    }

    private function key(string $state): string
    {
        return 'calendar:google:oauth:'.hash('sha256', $state);
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['state' => __('messages.calendar_oauth_invalid_state')]);
    }
}
