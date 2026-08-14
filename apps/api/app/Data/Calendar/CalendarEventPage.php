<?php

namespace App\Data\Calendar;

final class CalendarEventPage
{
    /** @param list<CalendarEventEnvelope> $events */
    public function __construct(
        public readonly array $events,
        public readonly ?string $nextCursor,
        public readonly bool $fullSnapshot,
    ) {}
}
