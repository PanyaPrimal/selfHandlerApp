<?php

namespace App\Data\Calendar;

use Carbon\CarbonImmutable;

final class CalendarWriteResult
{
    public function __construct(
        public readonly string $externalId,
        public readonly ?string $etag,
        public readonly ?CarbonImmutable $updatedAt,
    ) {}
}
