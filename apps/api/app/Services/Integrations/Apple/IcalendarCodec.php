<?php

namespace App\Services\Integrations\Apple;

use App\Data\Calendar\CalendarEventEnvelope;
use App\Exceptions\CalendarIntegrationException;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
use Throwable;

class IcalendarCodec
{
    public function encode(CalendarEventEnvelope $event, string $uid): string
    {
        $calendar = new VCalendar;
        $component = $calendar->add('VEVENT', [
            'UID' => $uid.'@selfhandler.local',
            'SUMMARY' => $event->summary ?? 'SelfHandler',
            'STATUS' => 'CONFIRMED',
            'DTSTAMP' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
            'X-SELFHANDLER-UID' => $uid,
        ]);
        if ($event->allDay) {
            $component->add('DTSTART', $event->startDate, ['VALUE' => 'DATE']);
            $component->add('DTEND', $event->endDate, ['VALUE' => 'DATE']);
        } else {
            $component->add('DTSTART', $event->startsAt?->toDateTimeImmutable());
            $component->add('DTEND', $event->endsAt?->toDateTimeImmutable());
        }

        return $calendar->serialize();
    }

    /** @return list<CalendarEventEnvelope> */
    public function parse(string $contents, string $href, ?string $etag): array
    {
        try {
            $calendar = Reader::read($contents, Reader::OPTION_FORGIVING);
            $events = [];
            foreach ($calendar->select('VEVENT') as $event) {
                $recurrence = isset($event->{'RECURRENCE-ID'}) ? (string) $event->{'RECURRENCE-ID'} : null;
                $externalId = $recurrence ? $href.'#'.$recurrence : $href;
                $status = strtolower((string) ($event->STATUS ?? 'CONFIRMED'));
                $status = in_array($status, ['confirmed', 'tentative', 'cancelled'], true) ? $status : 'confirmed';
                $summary = isset($event->SUMMARY) ? mb_substr((string) $event->SUMMARY, 0, 1000) : null;
                $updated = isset($event->{'LAST-MODIFIED'})
                    ? CarbonImmutable::instance($event->{'LAST-MODIFIED'}->getDateTime())->utc() : null;
                $origin = isset($event->{'X-SELFHANDLER-UID'}) ? (string) $event->{'X-SELFHANDLER-UID'} : null;
                $start = $event->DTSTART ?? null;
                $end = $event->DTEND ?? null;
                if (! $start || ! $end) {
                    continue;
                }
                if ($start->getValueType() === 'DATE') {
                    $events[] = CalendarEventEnvelope::allDay(
                        $externalId, $summary, (string) $start, (string) $end, $status, $etag, $updated, $origin,
                    );
                } else {
                    $events[] = CalendarEventEnvelope::timed(
                        $externalId, $summary,
                        CarbonImmutable::instance($start->getDateTime()),
                        CarbonImmutable::instance($end->getDateTime()),
                        $status, $etag, $updated, $origin,
                    );
                }
            }

            return $events;
        } catch (CalendarIntegrationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw CalendarIntegrationException::invalidResponse();
        }
    }
}
