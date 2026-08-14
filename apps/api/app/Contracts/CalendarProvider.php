<?php

namespace App\Contracts;

use App\Data\Calendar\CalendarDescriptor;
use App\Data\Calendar\CalendarEventEnvelope;
use App\Data\Calendar\CalendarEventPage;
use App\Data\Calendar\CalendarWriteResult;
use App\Models\Integration;
use Carbon\CarbonImmutable;

interface CalendarProvider
{
    public function provider(): string;

    public function configured(): bool;

    /** @return list<CalendarDescriptor> */
    public function calendars(Integration $integration): array;

    public function pull(
        Integration $integration,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $cursor,
    ): CalendarEventPage;

    public function upsert(
        Integration $integration,
        CalendarEventEnvelope $event,
        string $stableId,
        ?string $externalId,
        ?string $etag,
    ): CalendarWriteResult;

    public function delete(Integration $integration, string $externalId, ?string $etag): void;
}
