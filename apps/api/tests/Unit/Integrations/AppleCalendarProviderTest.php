<?php

namespace Tests\Unit\Integrations;

use App\Data\Calendar\CalendarEventEnvelope;
use App\Exceptions\CalendarIntegrationException;
use App\Models\Integration;
use App\Models\User;
use App\Services\Integrations\Apple\AppleCalendarProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppleCalendarProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('integrations.apple.discovery_url', 'https://caldav.apple.test/.well-known/caldav');
        config()->set('integrations.apple.allowed_hosts', ['caldav.apple.test']);
        Http::preventStrayRequests();
    }

    public function test_discovers_writable_calendars_without_exposing_credentials(): void
    {
        $integration = $this->integration();
        Http::fakeSequence()
            ->push($this->xmlResponse('<d:current-user-principal><d:href>/principal/</d:href></d:current-user-principal>'), 207)
            ->push($this->xmlResponse('<c:calendar-home-set><d:href>/calendars/</d:href></c:calendar-home-set>'), 207)
            ->push($this->calendarListResponse(), 207);

        $calendars = app(AppleCalendarProvider::class)->calendars($integration);

        $this->assertCount(1, $calendars);
        $this->assertSame('https://caldav.apple.test/calendars/personal/', $calendars[0]->id);
        $this->assertSame('Personal', $calendars[0]->name);
        $this->assertTrue($calendars[0]->writable);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PROPFIND'
            && $request->hasHeader('Authorization'));
    }

    public function test_pulls_icalendar_and_sync_token_and_writes_conditionally(): void
    {
        $integration = $this->integration([
            'external_calendar_id' => 'https://caldav.apple.test/calendars/personal/',
        ]);
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:event-1\r\n"
            ."DTSTART:20260814T070000Z\r\nDTEND:20260814T080000Z\r\nSUMMARY:Focus\r\n"
            ."STATUS:CONFIRMED\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        Http::fakeSequence()
            ->push($this->eventResponse('/calendars/personal/event-1.ics', 'etag-one', $ics, 'token-two'), 207)
            ->push('', 204, ['ETag' => 'etag-two'])
            ->push('', 204);

        $provider = app(AppleCalendarProvider::class);
        $page = $provider->pull(
            $integration,
            CarbonImmutable::parse('2026-05-16T00:00:00Z'),
            CarbonImmutable::parse('2027-08-15T00:00:00Z'),
            null,
        );
        $written = $provider->upsert(
            $integration,
            CalendarEventEnvelope::allDay(
                'ignored', 'Holiday', '2026-08-20', '2026-08-22', 'confirmed', null, null, 'stable',
            ),
            'stable',
            null,
            null,
        );
        $provider->delete($integration, $written->externalId, $written->etag);

        $this->assertSame('sync:token-two', $page->nextCursor);
        $this->assertSame('Focus', $page->events[0]->summary);
        $this->assertSame('etag-two', $written->etag);
        $this->assertStringEndsWith('/stable.ics', $written->externalId);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'REPORT');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->hasHeader('If-None-Match', '*'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_incremental_report_requests_changed_event_content_and_accepts_numbered_icloud_hosts(): void
    {
        config()->set('integrations.apple.allowed_hosts', ['caldav.icloud.com']);
        $integration = $this->integration([
            'external_calendar_id' => 'https://p123-caldav.icloud.com/calendars/personal/',
        ]);
        Http::fake([
            'https://p123-caldav.icloud.com/*' => Http::response($this->eventResponse(
                '/calendars/personal/event-1.ics',
                'etag-two',
                "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:event-1\r\nDTSTART:20260814T070000Z\r\nDTEND:20260814T080000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
                'token-three',
            ), 207),
        ]);

        $page = app(AppleCalendarProvider::class)->pull(
            $integration,
            CarbonImmutable::parse('2026-05-16T00:00:00Z'),
            CarbonImmutable::parse('2027-08-15T00:00:00Z'),
            'sync:token-two',
        );

        $this->assertSame('sync:token-three', $page->nextCursor);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'REPORT'
            && str_contains($request->body(), '<d:sync-token>token-two</d:sync-token>')
            && str_contains($request->body(), '<c:calendar-data/>'));
    }

    public function test_event_href_outside_selected_calendar_is_rejected(): void
    {
        $integration = $this->integration([
            'external_calendar_id' => 'https://caldav.apple.test/calendars/personal/',
        ]);
        Http::fake([
            '*' => Http::response($this->eventResponse(
                '/calendars/foreign/event-1.ics',
                'etag-one',
                "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:event-1\r\nDTSTART:20260814T070000Z\r\nDTEND:20260814T080000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
                'token-two',
            ), 207),
        ]);

        $this->expectException(CalendarIntegrationException::class);
        app(AppleCalendarProvider::class)->pull(
            $integration,
            CarbonImmutable::parse('2026-05-16T00:00:00Z'),
            CarbonImmutable::parse('2027-08-15T00:00:00Z'),
            null,
        );
    }

    private function integration(array $overrides = []): Integration
    {
        $owner = User::factory()->create();

        return Integration::query()->create([
            'user_id' => $owner->id,
            'provider' => Integration::PROVIDER_APPLE,
            'kind' => Integration::KIND_CALENDAR,
            'status' => Integration::STATUS_PENDING,
            'access_token' => 'owner@example.test',
            'external_account_label' => 'owner@example.test',
            'secret' => 'app-specific-secret',
            ...$overrides,
        ]);
    }

    private function xmlResponse(string $property): string
    {
        return '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            .'<d:response><d:href>/</d:href><d:propstat><d:prop>'.$property
            .'</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';
    }

    private function calendarListResponse(): string
    {
        return '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            .'<d:response><d:href>/calendars/personal/</d:href><d:propstat><d:prop>'
            .'<d:resourcetype><d:collection/><c:calendar/></d:resourcetype><d:displayname>Personal</d:displayname>'
            .'<d:current-user-privilege-set><d:privilege><d:write-content/></d:privilege></d:current-user-privilege-set>'
            .'</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>';
    }

    private function eventResponse(string $href, string $etag, string $ics, string $token): string
    {
        return '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            .'<d:response><d:href>'.$href.'</d:href><d:propstat><d:prop><d:getetag>'.$etag.'</d:getetag>'
            .'<c:calendar-data><![CDATA['.$ics.']]></c:calendar-data></d:prop>'
            .'<d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>'
            .'<d:sync-token>'.$token.'</d:sync-token></d:multistatus>';
    }
}
