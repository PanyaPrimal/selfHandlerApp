<?php

namespace App\Services\Integrations;

use App\Contracts\CalendarProvider;
use App\Exceptions\CalendarIntegrationException;
use App\Models\Integration;
use App\Services\Integrations\Apple\AppleCalendarProvider;
use App\Services\Integrations\Google\GoogleCalendarProvider;

class CalendarProviderRegistry
{
    public function __construct(
        private readonly GoogleCalendarProvider $google,
        private readonly AppleCalendarProvider $apple,
    ) {}

    /** @return list<CalendarProvider> */
    public function all(): array
    {
        return [$this->google, $this->apple];
    }

    public function for(string $provider): CalendarProvider
    {
        return match ($provider) {
            Integration::PROVIDER_GOOGLE => $this->google,
            Integration::PROVIDER_APPLE => $this->apple,
            default => throw CalendarIntegrationException::unavailable(),
        };
    }
}
