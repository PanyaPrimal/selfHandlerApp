<?php

namespace App\Services\Integrations\Google;

use App\Contracts\CalendarProvider;
use App\Data\Calendar\CalendarDescriptor;
use App\Data\Calendar\CalendarEventEnvelope;
use App\Data\Calendar\CalendarEventPage;
use App\Data\Calendar\CalendarWriteResult;
use App\Exceptions\CalendarIntegrationException;
use App\Models\Integration;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GoogleCalendarProvider implements CalendarProvider
{
    public function provider(): string
    {
        return Integration::PROVIDER_GOOGLE;
    }

    public function configured(): bool
    {
        return filled(config('integrations.google.client_id'))
            && filled(config('integrations.google.client_secret'))
            && filled(config('integrations.google.redirect_uri'));
    }

    public function authorizationUrl(string $state): string
    {
        if (! $this->configured()) {
            throw CalendarIntegrationException::unavailable();
        }

        return (string) config('integrations.google.authorization_url').'?'.http_build_query([
            'client_id' => config('integrations.google.client_id'),
            'redirect_uri' => config('integrations.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', config('integrations.google.scopes')),
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{access_token:string,refresh_token:?string,expires_at:CarbonImmutable} */
    public function exchangeCode(string $code): array
    {
        if (! $this->configured()) {
            throw CalendarIntegrationException::unavailable();
        }
        $response = $this->send(fn (): Response => $this->client()->asForm()->post(config('integrations.google.token_url'), [
            'client_id' => config('integrations.google.client_id'),
            'client_secret' => config('integrations.google.client_secret'),
            'redirect_uri' => config('integrations.google.redirect_uri'),
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]));
        $this->assertSuccessful($response, true);
        $access = $response->json('access_token');
        if (! is_string($access) || $access === '') {
            throw CalendarIntegrationException::invalidResponse();
        }

        return [
            'access_token' => $access,
            'refresh_token' => is_string($response->json('refresh_token')) ? $response->json('refresh_token') : null,
            'expires_at' => CarbonImmutable::now()->addSeconds(max(60, (int) $response->json('expires_in', 3600))),
        ];
    }

    public function calendars(Integration $integration): array
    {
        $items = [];
        $pageToken = null;
        do {
            $query = ['maxResults' => 100, 'showHidden' => false];
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }
            $response = $this->send(fn (): Response => $this->authorized($integration)->get(
                $this->api('/users/me/calendarList'),
                $query,
            ));
            $this->assertSuccessful($response);
            foreach ($response->json('items', []) as $item) {
                if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                    continue;
                }
                $role = (string) ($item['accessRole'] ?? 'reader');
                $items[] = new CalendarDescriptor(
                    id: $item['id'],
                    name: is_string($item['summary'] ?? null) ? $item['summary'] : $item['id'],
                    timezone: is_string($item['timeZone'] ?? null) ? $item['timeZone'] : null,
                    writable: in_array($role, ['owner', 'writer'], true),
                    default: (bool) ($item['primary'] ?? false),
                );
                if (count($items) >= (int) config('integrations.sync.max_calendars', 100)) {
                    return $items;
                }
            }
            $pageToken = $response->json('nextPageToken');
            $pageToken = is_string($pageToken) && $pageToken !== '' ? $pageToken : null;
        } while ($pageToken !== null);

        return $items;
    }

    public function pull(
        Integration $integration,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $cursor,
    ): CalendarEventPage {
        $calendar = $this->calendarId($integration);
        $events = [];
        $pageToken = null;
        $nextCursor = null;
        do {
            $query = ['maxResults' => 2500, 'showDeleted' => true, 'singleEvents' => true];
            if ($cursor !== null) {
                $query['syncToken'] = $cursor;
            } else {
                $query['timeMin'] = $from->utc()->toRfc3339String();
                $query['timeMax'] = $to->utc()->toRfc3339String();
                $query['orderBy'] = 'startTime';
            }
            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }
            $response = $this->send(fn (): Response => $this->authorized($integration)->get(
                $this->api('/calendars/'.rawurlencode($calendar).'/events'),
                $query,
            ));
            if ($response->status() === 410) {
                throw CalendarIntegrationException::cursor();
            }
            $this->assertSuccessful($response);
            foreach ($response->json('items', []) as $item) {
                if (is_array($item) && ($normalized = $this->normalize($item))) {
                    $events[] = $normalized;
                    if (count($events) > (int) config('integrations.sync.max_events', 5000)) {
                        throw CalendarIntegrationException::invalidResponse();
                    }
                }
            }
            $pageToken = $response->json('nextPageToken');
            $pageToken = is_string($pageToken) && $pageToken !== '' ? $pageToken : null;
            $candidate = $response->json('nextSyncToken');
            if (is_string($candidate) && $candidate !== '') {
                $nextCursor = $candidate;
            }
        } while ($pageToken !== null);

        if ($nextCursor === null) {
            throw CalendarIntegrationException::invalidResponse();
        }

        return new CalendarEventPage($events, $nextCursor, $cursor === null);
    }

    public function upsert(
        Integration $integration,
        CalendarEventEnvelope $event,
        string $stableId,
        ?string $externalId,
        ?string $etag,
    ): CalendarWriteResult {
        $body = $this->eventBody($event, $stableId);
        $calendarUrl = $this->api('/calendars/'.rawurlencode($this->calendarId($integration)).'/events');
        if ($externalId === null) {
            $body['id'] = substr(preg_replace('/[^a-v0-9]/', '', strtolower($stableId)) ?: hash('sha256', $stableId), 0, 48);
            $response = $this->send(fn (): Response => $this->authorized($integration)->post($calendarUrl, $body));
        } else {
            $response = $this->send(fn (): Response => $this->authorized($integration)
                ->withHeaders($etag ? ['If-Match' => $etag] : [])
                ->put($calendarUrl.'/'.rawurlencode($externalId), $body));
        }
        $this->assertSuccessful($response);
        $id = $response->json('id');
        if (! is_string($id) || $id === '') {
            throw CalendarIntegrationException::invalidResponse();
        }

        return new CalendarWriteResult(
            $id,
            is_string($response->json('etag')) ? $response->json('etag') : null,
            is_string($response->json('updated')) ? CarbonImmutable::parse($response->json('updated'))->utc() : null,
        );
    }

    public function delete(Integration $integration, string $externalId, ?string $etag): void
    {
        $response = $this->send(fn (): Response => $this->authorized($integration)
            ->withHeaders($etag ? ['If-Match' => $etag] : [])
            ->delete($this->api('/calendars/'.rawurlencode($this->calendarId($integration)).'/events/'.rawurlencode($externalId))));
        if (! in_array($response->status(), [200, 204, 404, 410], true)) {
            $this->assertSuccessful($response);
        }
    }

    private function normalize(array $item): ?CalendarEventEnvelope
    {
        $id = $item['id'] ?? null;
        if (! is_string($id) || $id === '') {
            return null;
        }
        $status = in_array($item['status'] ?? null, ['confirmed', 'tentative', 'cancelled'], true)
            ? $item['status'] : 'confirmed';
        $summary = is_string($item['summary'] ?? null) ? mb_substr($item['summary'], 0, 1000) : null;
        $etag = is_string($item['etag'] ?? null) ? $item['etag'] : null;
        $updated = is_string($item['updated'] ?? null) ? CarbonImmutable::parse($item['updated'])->utc() : null;
        $origin = data_get($item, 'extendedProperties.private.selfhandler_uid');
        $origin = is_string($origin) ? $origin : null;

        if ($status === 'cancelled' && ! isset($item['start'])) {
            return CalendarEventEnvelope::tombstone($id, $etag, $updated);
        }

        if (is_string(data_get($item, 'start.date')) && is_string(data_get($item, 'end.date'))) {
            return CalendarEventEnvelope::allDay(
                $id, $summary, data_get($item, 'start.date'), data_get($item, 'end.date'),
                $status, $etag, $updated, $origin,
            );
        }
        if (is_string(data_get($item, 'start.dateTime')) && is_string(data_get($item, 'end.dateTime'))) {
            return CalendarEventEnvelope::timed(
                $id, $summary, CarbonImmutable::parse(data_get($item, 'start.dateTime')),
                CarbonImmutable::parse(data_get($item, 'end.dateTime')), $status, $etag, $updated, $origin,
            );
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function eventBody(CalendarEventEnvelope $event, string $stableId): array
    {
        return [
            'summary' => $event->summary ?? 'SelfHandler',
            'status' => 'confirmed',
            'start' => $event->allDay ? ['date' => $event->startDate] : ['dateTime' => $event->startsAt?->toRfc3339String()],
            'end' => $event->allDay ? ['date' => $event->endDate] : ['dateTime' => $event->endsAt?->toRfc3339String()],
            'extendedProperties' => ['private' => ['selfhandler_uid' => $stableId]],
        ];
    }

    private function authorized(Integration $integration): PendingRequest
    {
        if ($integration->token_expires_at?->lessThanOrEqualTo(CarbonImmutable::now()->addMinute())) {
            $this->refresh($integration);
        }
        if (! is_string($integration->access_token) || $integration->access_token === '') {
            throw CalendarIntegrationException::auth();
        }

        return $this->client()->acceptJson()->withToken($integration->access_token);
    }

    private function refresh(Integration $integration): void
    {
        if (! is_string($integration->refresh_token) || $integration->refresh_token === '') {
            throw CalendarIntegrationException::auth();
        }
        $response = $this->send(fn (): Response => $this->client()->asForm()->post(config('integrations.google.token_url'), [
            'client_id' => config('integrations.google.client_id'),
            'client_secret' => config('integrations.google.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $integration->refresh_token,
        ]));
        $this->assertSuccessful($response, true);
        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw CalendarIntegrationException::invalidResponse();
        }
        $integration->forceFill([
            'access_token' => $token,
            'refresh_token' => is_string($response->json('refresh_token'))
                ? $response->json('refresh_token') : $integration->refresh_token,
            'token_expires_at' => CarbonImmutable::now()->addSeconds(max(60, (int) $response->json('expires_in', 3600))),
        ])->save();
    }

    private function assertSuccessful(Response $response, bool $authOperation = false): void
    {
        if ($response->successful()) {
            return;
        }
        if ($authOperation || in_array($response->status(), [401, 403], true)) {
            throw CalendarIntegrationException::auth();
        }
        if ($response->status() === 429) {
            throw new CalendarIntegrationException('calendar_rate_limited', 429);
        }
        if ($response->serverError()) {
            throw new CalendarIntegrationException('calendar_sync_failed');
        }
        throw CalendarIntegrationException::invalidResponse();
    }

    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw new CalendarIntegrationException('calendar_provider_timeout');
        }
    }

    private function client(): PendingRequest
    {
        return Http::timeout((int) config('integrations.sync.timeout_seconds', 15))
            ->connectTimeout((int) config('integrations.sync.connect_timeout_seconds', 5))
            ->retry(2, 100, throw: false);
    }

    private function api(string $path): string
    {
        return rtrim((string) config('integrations.google.api_url'), '/').$path;
    }

    private function calendarId(Integration $integration): string
    {
        if (! is_string($integration->external_calendar_id) || $integration->external_calendar_id === '') {
            throw new CalendarIntegrationException('calendar_not_found', 409);
        }

        return $integration->external_calendar_id;
    }
}
