<?php

namespace App\Models;

use App\Support\UserOwned;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class LlmConnection extends Model
{
    use UserOwned;

    public const PROVIDER_ANTHROPIC = 'anthropic';

    public const PROVIDER_OPENAI = 'openai';

    public const PROVIDERS = [self::PROVIDER_ANTHROPIC, self::PROVIDER_OPENAI];

    public const STATUS_UNTESTED = 'untested';

    public const STATUS_READY = 'ready';

    public const STATUS_INVALID = 'invalid';

    public const STATUSES = [self::STATUS_UNTESTED, self::STATUS_READY, self::STATUS_INVALID];

    protected $fillable = [
        'user_id', 'name', 'provider', 'model', 'api_key', 'key_hint', 'parameters', 'status',
        'last_tested_at', 'last_used_at', 'last_error_code',
    ];

    protected $hidden = ['user_id', 'api_key', 'key_hint'];

    protected static function booted(): void
    {
        static::creating(function (LlmConnection $connection): void {
            $connection->status ??= self::STATUS_UNTESTED;
            $connection->parameters = self::normalizeParameters($connection->parameters);
            self::assertContract($connection);
        });
        static::saving(function (LlmConnection $connection): void {
            if ($connection->exists) {
                $connection->parameters = self::normalizeParameters($connection->parameters);
                self::assertContract($connection);
            }
        });
    }

    /** @return array{max_output_tokens:int} */
    public static function defaultParameters(): array
    {
        return ['max_output_tokens' => (int) config('ai.default_max_output_tokens', 512)];
    }

    /** @return array{max_output_tokens:int} */
    public static function normalizeParameters(mixed $value): array
    {
        if ($value === null || $value === []) {
            return self::defaultParameters();
        }
        if (! is_array($value) || array_keys($value) !== ['max_output_tokens']) {
            throw new LogicException('LLM parameters are outside the closed contract.');
        }

        return ['max_output_tokens' => (int) $value['max_output_tokens']];
    }

    public function keyMask(): string
    {
        return '••••'.$this->key_hint;
    }

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'parameters' => 'array',
            'last_tested_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    private static function assertContract(LlmConnection $connection): void
    {
        $tokens = (int) ($connection->parameters['max_output_tokens'] ?? 0);
        if (blank($connection->user_id)
            || ! in_array($connection->provider, self::PROVIDERS, true)
            || ! in_array($connection->status, self::STATUSES, true)
            || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,159}$/', (string) $connection->model)
            || mb_strlen((string) $connection->key_hint) !== 4
            || $tokens < (int) config('ai.minimum_max_output_tokens', 128)
            || $tokens > (int) config('ai.maximum_max_output_tokens', 2048)) {
            throw new LogicException('LLM connection is outside the supported contract.');
        }
    }
}
