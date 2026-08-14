<?php

namespace App\Data\Calendar;

use Carbon\CarbonImmutable;
use LogicException;

final class CalendarEventEnvelope
{
    private function __construct(
        public readonly string $externalId,
        public readonly ?string $summary,
        public readonly ?CarbonImmutable $startsAt,
        public readonly ?CarbonImmutable $endsAt,
        public readonly ?string $startDate,
        public readonly ?string $endDate,
        public readonly bool $allDay,
        public readonly string $status,
        public readonly ?string $etag,
        public readonly ?CarbonImmutable $updatedAt,
        public readonly ?string $originKey,
    ) {
        if ($externalId === '' || ! in_array($status, ['confirmed', 'tentative', 'cancelled'], true)) {
            throw new LogicException('Calendar envelope identity or status is invalid.');
        }
        if ($status === 'cancelled' && $startsAt === null && $endsAt === null
            && $startDate === null && $endDate === null) {
            return;
        }
        if ($allDay
            ? ! ($startDate !== null && $endDate !== null && $startDate < $endDate
                && $startsAt === null && $endsAt === null)
            : ! ($startsAt !== null && $endsAt !== null && $startsAt->lessThan($endsAt)
                && $startDate === null && $endDate === null)) {
            throw new LogicException('Calendar envelope time shape is invalid.');
        }
    }

    public static function timed(
        string $externalId,
        ?string $summary,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        string $status,
        ?string $etag,
        ?CarbonImmutable $updatedAt,
        ?string $originKey = null,
    ): self {
        return new self(
            $externalId, $summary, $startsAt->utc(), $endsAt->utc(), null, null, false,
            $status, $etag, $updatedAt?->utc(), $originKey,
        );
    }

    public static function allDay(
        string $externalId,
        ?string $summary,
        string $startDate,
        string $endDate,
        string $status,
        ?string $etag,
        ?CarbonImmutable $updatedAt,
        ?string $originKey = null,
    ): self {
        return new self(
            $externalId, $summary, null, null, $startDate, $endDate, true,
            $status, $etag, $updatedAt?->utc(), $originKey,
        );
    }

    public static function tombstone(
        string $externalId,
        ?string $etag = null,
        ?CarbonImmutable $updatedAt = null,
    ): self {
        return new self(
            $externalId, null, null, null, null, null, false,
            'cancelled', $etag, $updatedAt?->utc(), null,
        );
    }

    public function isTombstone(): bool
    {
        return $this->status === 'cancelled' && $this->startsAt === null && $this->startDate === null;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            $this->summary, $this->startsAt?->toIso8601String(), $this->endsAt?->toIso8601String(),
            $this->startDate, $this->endDate, $this->allDay, $this->status,
        ], JSON_THROW_ON_ERROR));
    }
}
