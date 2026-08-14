<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AiAssistantException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $httpStatus = 422)
    {
        parent::__construct($errorCode);
    }

    public static function activeRequired(): self
    {
        return new self('ai_active_connection_required', 409);
    }

    public static function notReady(): self
    {
        return new self('ai_connection_not_ready', 409);
    }

    public static function consentRequired(): self
    {
        return new self('ai_consent_required', 409);
    }

    public static function credentialsInvalid(): self
    {
        return new self('ai_credentials_invalid');
    }

    public static function providerRateLimited(): self
    {
        return new self('ai_provider_rate_limited', 429);
    }

    public static function providerTimeout(): self
    {
        return new self('ai_provider_timeout', 503);
    }

    public static function providerUnavailable(): self
    {
        return new self('ai_provider_unavailable', 503);
    }

    public static function unsupportedCapability(): self
    {
        return new self('ai_provider_unsupported_capability');
    }

    public static function providerRefused(): self
    {
        return new self('ai_provider_refused');
    }

    public static function invalidResponse(): self
    {
        return new self('ai_provider_invalid_response');
    }

    public static function toolNotAllowed(): self
    {
        return new self('ai_tool_not_allowed');
    }

    public static function confirmationRequired(): self
    {
        return new self('ai_tool_confirmation_required', 409);
    }

    public static function confirmationExpired(): self
    {
        return new self('ai_confirmation_expired', 409);
    }

    public static function confirmationReplayed(): self
    {
        return new self('ai_confirmation_replayed', 409);
    }

    public static function confirmationStale(): self
    {
        return new self('ai_confirmation_stale', 409);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => __('messages.'.$this->errorCode),
            'code' => $this->errorCode,
        ], $this->httpStatus);
    }
}
