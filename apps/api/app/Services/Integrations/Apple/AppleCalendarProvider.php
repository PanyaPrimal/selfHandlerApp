<?php

namespace App\Services\Integrations\Apple;

use App\Contracts\CalendarProvider;
use App\Data\Calendar\CalendarDescriptor;
use App\Data\Calendar\CalendarEventEnvelope;
use App\Data\Calendar\CalendarEventPage;
use App\Data\Calendar\CalendarWriteResult;
use App\Exceptions\CalendarIntegrationException;
use App\Models\Integration;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class AppleCalendarProvider implements CalendarProvider
{
    public function __construct(private readonly IcalendarCodec $codec) {}

    public function provider(): string
    {
        return Integration::PROVIDER_APPLE;
    }

    public function configured(): bool
    {
        return filled(config('integrations.apple.discovery_url'));
    }

    public function calendars(Integration $integration): array
    {
        $discoveryUrl = (string) config('integrations.apple.discovery_url');
        $principalResponse = $this->dav(
            $integration,
            'PROPFIND',
            $discoveryUrl,
            $this->propertyRequest('<d:current-user-principal/>'),
            ['Depth' => '0'],
        );
        $principal = $this->resolveUrl(
            $discoveryUrl,
            $this->firstText($this->xml($principalResponse), '//*[local-name()="current-user-principal"]/*[local-name()="href"]'),
        );
        $homeResponse = $this->dav(
            $integration,
            'PROPFIND',
            $principal,
            $this->propertyRequest('<c:calendar-home-set/>'),
            ['Depth' => '0'],
        );
        $home = $this->resolveUrl(
            $principal,
            $this->firstText($this->xml($homeResponse), '//*[local-name()="calendar-home-set"]/*[local-name()="href"]'),
        );
        $listResponse = $this->dav(
            $integration,
            'PROPFIND',
            $home,
            $this->propertyRequest(
                '<d:resourcetype/><d:displayname/><d:current-user-privilege-set/><c:calendar-timezone/>',
            ),
            ['Depth' => '1'],
        );
        $document = $this->xml($listResponse);
        $xpath = new DOMXPath($document);
        $result = [];

        foreach ($xpath->query('//*[local-name()="response"]') ?: [] as $node) {
            if (! $node instanceof DOMElement
                || ! $this->has($xpath, './/*[local-name()="resourcetype"]/*[local-name()="calendar"]', $node)) {
                continue;
            }
            $href = $this->firstTextFrom($xpath, './/*[local-name()="href"]', $node);
            $name = $this->firstTextFrom($xpath, './/*[local-name()="displayname"]', $node, $href);
            $writable = $this->has(
                $xpath,
                './/*[local-name()="current-user-privilege-set"]//*[local-name()="write" or local-name()="write-content"]',
                $node,
            );
            $timezone = $this->timezoneFrom(
                $this->firstTextFrom($xpath, './/*[local-name()="calendar-timezone"]', $node, ''),
            );
            $result[] = new CalendarDescriptor(
                $this->resolveUrl($home, $href),
                mb_substr($name, 0, 512),
                $timezone,
                $writable,
                count($result) === 0,
            );
            if (count($result) >= (int) config('integrations.sync.max_calendars', 100)) {
                break;
            }
        }

        return $result;
    }

    public function pull(
        Integration $integration,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $cursor,
    ): CalendarEventPage {
        $calendar = $this->calendarUrl($integration);
        $syncToken = is_string($cursor) && str_starts_with($cursor, 'sync:') ? substr($cursor, 5) : null;
        $body = $syncToken !== null
            ? '<?xml version="1.0"?><d:sync-collection xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:sync-token>'
                .htmlspecialchars($syncToken, ENT_XML1).'</d:sync-token><d:sync-level>1</d:sync-level>'
                .'<d:prop><d:getetag/><c:calendar-data/></d:prop></d:sync-collection>'
            : '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
                .'<d:prop><d:getetag/><c:calendar-data><c:expand start="'.$from->utc()->format('Ymd\THis\Z')
                .'" end="'.$to->utc()->format('Ymd\THis\Z').'"/></c:calendar-data></d:prop>'
                .'<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">'
                .'<c:time-range start="'.$from->utc()->format('Ymd\THis\Z').'" end="'
                .$to->utc()->format('Ymd\THis\Z').'"/></c:comp-filter></c:comp-filter></c:filter></c:calendar-query>';
        $response = $this->dav($integration, 'REPORT', $calendar, $body, ['Depth' => '1'], allowCursorFailure: true);
        if (in_array($response->status(), [403, 409, 410], true) && $syncToken !== null) {
            throw CalendarIntegrationException::cursor();
        }
        $document = $this->xml($response);
        $xpath = new DOMXPath($document);
        $events = [];
        foreach ($xpath->query('//*[local-name()="response"]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $href = $this->resolveUrl($calendar, $this->firstTextFrom($xpath, './*[local-name()="href"]', $node));
            $this->assertChildUrl($calendar, $href);
            $statusText = $this->firstTextFrom($xpath, './/*[local-name()="status"]', $node, '');
            $etag = $this->firstTextFrom($xpath, './/*[local-name()="getetag"]', $node, '');
            $etag = $etag !== '' ? $etag : null;
            $data = $this->firstTextFrom($xpath, './/*[local-name()="calendar-data"]', $node, '');
            if ($data === '' && str_contains($statusText, '404')) {
                $events[] = CalendarEventEnvelope::tombstone($href, $etag);

                continue;
            }
            if ($data !== '') {
                array_push($events, ...$this->codec->parse($data, $href, $etag));
            }
            if (count($events) > (int) config('integrations.sync.max_events', 5000)) {
                throw CalendarIntegrationException::invalidResponse();
            }
        }
        $next = $this->firstTextFrom($xpath, '/*[local-name()="multistatus"]/*[local-name()="sync-token"]', $document, '');

        return new CalendarEventPage($events, $next !== '' ? 'sync:'.$next : null, $syncToken === null);
    }

    public function upsert(
        Integration $integration,
        CalendarEventEnvelope $event,
        string $stableId,
        ?string $externalId,
        ?string $etag,
    ): CalendarWriteResult {
        $calendar = $this->calendarUrl($integration);
        $eventUrl = $externalId ?? rtrim($calendar, '/').'/'.rawurlencode($stableId).'.ics';
        $this->assertChildUrl($calendar, $eventUrl);
        $headers = ['Content-Type' => 'text/calendar; charset=utf-8'];
        if ($externalId === null) {
            $headers['If-None-Match'] = '*';
        } elseif ($etag !== null) {
            $headers['If-Match'] = $etag;
        }
        $response = $this->dav(
            $integration,
            'PUT',
            $eventUrl,
            $this->codec->encode($event, $stableId),
            $headers,
        );

        return new CalendarWriteResult($eventUrl, $response->header('ETag'), CarbonImmutable::now());
    }

    public function delete(Integration $integration, string $externalId, ?string $etag): void
    {
        $calendar = $this->calendarUrl($integration);
        $this->assertChildUrl($calendar, $externalId);
        $headers = $etag !== null ? ['If-Match' => $etag] : [];
        $response = $this->request($integration, 'DELETE', $externalId, null, $headers);
        if (! in_array($response->status(), [200, 204, 404, 410], true)) {
            $this->assertSuccessful($response);
        }
    }

    private function dav(
        Integration $integration,
        string $method,
        string $url,
        string $body,
        array $headers,
        bool $allowCursorFailure = false,
    ): Response {
        $response = $this->request(
            $integration,
            $method,
            $url,
            $body,
            ['Content-Type' => 'application/xml; charset=utf-8', ...$headers],
        );
        if (! $allowCursorFailure || ! in_array($response->status(), [403, 409, 410], true)) {
            $this->assertSuccessful($response);
        }

        return $response;
    }

    private function request(
        Integration $integration,
        string $method,
        string $url,
        ?string $body,
        array $headers,
    ): Response {
        $this->assertAllowedUrl($url);
        $username = $integration->access_token;
        $password = $integration->secret;
        if (! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
            throw CalendarIntegrationException::auth();
        }
        try {
            $response = $this->client($username, $password)->withHeaders($headers)->send($method, $url, [
                'body' => $body,
            ]);
        } catch (ConnectionException) {
            throw new CalendarIntegrationException('calendar_provider_timeout');
        }
        for ($redirects = 0; in_array($response->status(), [301, 302, 307, 308], true) && $redirects < 3; $redirects++) {
            $location = $response->header('Location');
            if (! is_string($location) || $location === '') {
                throw CalendarIntegrationException::invalidResponse();
            }
            $url = $this->resolveUrl($url, $location);
            try {
                $response = $this->client($username, $password)->withHeaders($headers)->send($method, $url, [
                    'body' => $body,
                ]);
            } catch (ConnectionException) {
                throw new CalendarIntegrationException('calendar_provider_timeout');
            }
        }

        return $response;
    }

    private function client(string $username, string $password): PendingRequest
    {
        return Http::withBasicAuth($username, $password)
            ->timeout((int) config('integrations.sync.timeout_seconds', 15))
            ->connectTimeout((int) config('integrations.sync.connect_timeout_seconds', 5))
            ->retry(2, 100, throw: false)
            ->withOptions(['allow_redirects' => false]);
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->successful() || $response->status() === 207) {
            return;
        }
        if (in_array($response->status(), [401, 403], true)) {
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

    private function xml(Response $response): DOMDocument
    {
        try {
            $document = new DOMDocument;
            if (! @$document->loadXML($response->body(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw CalendarIntegrationException::invalidResponse();
            }

            return $document;
        } catch (CalendarIntegrationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw CalendarIntegrationException::invalidResponse();
        }
    }

    private function propertyRequest(string $properties): string
    {
        return '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            .'<d:prop>'.$properties.'</d:prop></d:propfind>';
    }

    private function firstText(DOMDocument $document, string $query): string
    {
        return $this->firstTextFrom(new DOMXPath($document), $query, $document);
    }

    private function firstTextFrom(DOMXPath $xpath, string $query, DOMNode $context, ?string $default = null): string
    {
        $node = $xpath->query($query, $context)?->item(0);
        $value = $node ? trim($node->textContent) : $default;
        if (! is_string($value)) {
            throw CalendarIntegrationException::invalidResponse();
        }

        return $value;
    }

    private function has(DOMXPath $xpath, string $query, DOMNode $context): bool
    {
        return ($xpath->query($query, $context)?->length ?? 0) > 0;
    }

    private function resolveUrl(string $base, string $reference): string
    {
        if (filter_var($reference, FILTER_VALIDATE_URL)) {
            $url = $reference;
        } else {
            $parts = parse_url($base);
            if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
                throw CalendarIntegrationException::invalidResponse();
            }
            $path = str_starts_with($reference, '/') ? $reference
                : rtrim(dirname((string) ($parts['path'] ?? '/')), '/').'/'.$reference;
            $url = $parts['scheme'].'://'.$parts['host'].($parts['port'] ?? null ? ':'.$parts['port'] : '').$path;
        }
        $this->assertAllowedUrl($url);

        return $url;
    }

    private function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $allowed = config('integrations.apple.allowed_hosts', []);
        $isAllowedHost = in_array($host, $allowed, true)
            || preg_match('/^p\d+-caldav\.icloud\.com$/D', $host) === 1;
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ! $isAllowedHost
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw CalendarIntegrationException::invalidResponse();
        }
    }

    private function assertChildUrl(string $calendar, string $event): void
    {
        $this->assertAllowedUrl($event);
        if (! str_starts_with($event, rtrim($calendar, '/').'/')) {
            throw CalendarIntegrationException::invalidResponse();
        }
    }

    private function calendarUrl(Integration $integration): string
    {
        $url = $integration->external_calendar_id;
        if (! is_string($url) || $url === '') {
            throw new CalendarIntegrationException('calendar_not_found', 409);
        }
        $this->assertAllowedUrl($url);

        return $url;
    }

    private function timezoneFrom(string $calendarTimezone): ?string
    {
        if (preg_match('/^TZID:([^\r\n]+)/m', $calendarTimezone, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}
