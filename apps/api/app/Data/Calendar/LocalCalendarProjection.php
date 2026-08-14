<?php

namespace App\Data\Calendar;

final class LocalCalendarProjection
{
    public function __construct(
        public readonly string $localType,
        public readonly int $localId,
        public readonly string $category,
        public readonly string $stableId,
        public readonly CalendarEventEnvelope $event,
    ) {}

    public function localKey(): string
    {
        return $this->localType.':'.$this->localId;
    }
}
