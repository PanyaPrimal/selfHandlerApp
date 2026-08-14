<?php

namespace App\Exceptions;

use RuntimeException;

class CalendarIntegrationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus = 502,
        public readonly bool $authenticationFailure = false,
        public readonly bool $invalidCursor = false,
    ) {
        parent::__construct($errorCode);
    }

    public static function unavailable(): self
    {
        return new self('calendar_provider_unavailable', 409);
    }

    public static function invalidResponse(): self
    {
        return new self('calendar_provider_invalid_response');
    }

    public static function auth(): self
    {
        return new self('calendar_auth_expired', 409, true);
    }

    public static function cursor(): self
    {
        return new self('calendar_sync_failed', 409, false, true);
    }
}
