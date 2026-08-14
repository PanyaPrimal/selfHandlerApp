<?php

namespace Tests\Unit\Integrations;

use App\Data\Calendar\CalendarEventEnvelope;
use App\Exceptions\CalendarIntegrationException;
use App\Models\Integration;
use App\Models\User;
use App\Services\Integrations\Apple\IcalendarCodec;
use App\Services\Integrations\Google\GoogleCalendarProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CalendarProviderContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('integrations.google', [
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://selfhandler.test/api/integrations/calendars/google/callback',
            'authorization_url' => 'https://accounts.google.test/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.google.test/token',
            'api_url' => 'https://calendar.google.test/calendar/v3',
            'scopes' => [
                'https://www.googleapis.com/auth/calendar.events',
                'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
            ],
        ]);
        Http::preventStrayRequests();
    }

    public function test_google_authorization_is_offline_stateful_and_least_scoped(): void
    {
        $url = app(GoogleCalendarProvider::class)->authorizationUrl('opaque-state');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('test-client', $query['client_id']);
        $this->assertSame('opaque-state', $query['state']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame(config('integrations.google.scopes'), explode(' ', $query['scope']));
    }

    public function test_google_calendar_discovery_and_paginated_incremental_pull_are_normalized(): void
    {
        $integration = $this->googleIntegration();
        Http::fake([
            'https://calendar.google.test/calendar/v3/users/me/calendarList*' => Http::response([
                'items' => [[
                    'id' => 'primary@example.test', 'summary' => 'Personal', 'timeZone' => 'Europe/Kyiv',
                    'accessRole' => 'owner', 'primary' => true,
                ]],
            ]),
            'https://calendar.google.test/calendar/v3/calendars/*/events*' => Http::sequence()
                ->push([
                    'items' => [[
                        'id' => 'first', 'etag' => 'one', 'updated' => '2026-08-14T08:00:00Z',
                        'status' => 'confirmed', 'summary' => 'Private meeting',
                        'start' => ['dateTime' => '2026-08-14T10:00:00+03:00'],
                        'end' => ['dateTime' => '2026-08-14T11:00:00+03:00'],
                    ]],
                    'nextPageToken' => 'page-2',
                ])
                ->push([
                    'items' => [[
                        'id' => 'holiday', 'etag' => 'two', 'updated' => '2026-08-14T08:00:00Z',
                        'status' => 'confirmed', 'summary' => 'Holiday',
                        'start' => ['date' => '2026-08-20'], 'end' => ['date' => '2026-08-22'],
                    ]],
                    'nextSyncToken' => 'next-sync',
                ]),
        ]);

        $calendars = app(GoogleCalendarProvider::class)->calendars($integration);
        $page = app(GoogleCalendarProvider::class)->pull(
            $integration,
            CarbonImmutable::parse('2026-05-16T00:00:00Z'),
            CarbonImmutable::parse('2027-08-15T00:00:00Z'),
            'previous-sync',
        );

        $this->assertSame('Personal', $calendars[0]->name);
        $this->assertTrue($calendars[0]->writable);
        $this->assertCount(2, $page->events);
        $this->assertSame('2026-08-14T07:00:00+00:00', $page->events[0]->startsAt?->toIso8601String());
        $this->assertTrue($page->events[1]->allDay);
        $this->assertSame('2026-08-22', $page->events[1]->endDate);
        $this->assertSame('next-sync', $page->nextCursor);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'syncToken=previous-sync'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'pageToken=page-2'));
    }

    public function test_google_network_failure_is_mapped_to_a_closed_timeout_code(): void
    {
        Http::fake(['*' => Http::failedConnection('provider detail that must stay private')]);

        try {
            app(GoogleCalendarProvider::class)->calendars($this->googleIntegration());
            $this->fail('The provider connection failure was accepted.');
        } catch (CalendarIntegrationException $exception) {
            $this->assertSame('calendar_provider_timeout', $exception->errorCode);
            $this->assertSame(502, $exception->httpStatus);
            $this->assertStringNotContainsString('provider detail', $exception->getMessage());
        }
    }

    public function test_icalendar_codec_round_trips_timed_all_day_and_escaped_text(): void
    {
        $codec = app(IcalendarCodec::class);
        $timed = CalendarEventEnvelope::timed(
            externalId: 'ignored',
            summary: "Plan, focus; now\nSecond line",
            startsAt: CarbonImmutable::parse('2026-08-14T07:00:00Z'),
            endsAt: CarbonImmutable::parse('2026-08-14T08:00:00Z'),
            status: 'confirmed',
            etag: null,
            updatedAt: null,
            originKey: 'stable-key',
        );
        $allDay = CalendarEventEnvelope::allDay(
            externalId: 'all-day',
            summary: 'Holiday',
            startDate: '2026-08-20',
            endDate: '2026-08-22',
            status: 'confirmed',
            etag: null,
            updatedAt: null,
            originKey: 'all-day-key',
        );

        $parsedTimed = $codec->parse($codec->encode($timed, 'stable-key'), '/stable-key.ics', 'etag-1');
        $parsedAllDay = $codec->parse($codec->encode($allDay, 'all-day-key'), '/all-day-key.ics', 'etag-2');

        $this->assertSame($timed->summary, $parsedTimed[0]->summary);
        $this->assertSame('2026-08-14T07:00:00+00:00', $parsedTimed[0]->startsAt?->toIso8601String());
        $this->assertTrue($parsedAllDay[0]->allDay);
        $this->assertSame('2026-08-22', $parsedAllDay[0]->endDate);
    }

    private function googleIntegration(): Integration
    {
        $user = User::factory()->create();

        return Integration::query()->create([
            'user_id' => $user->id,
            'provider' => Integration::PROVIDER_GOOGLE,
            'kind' => Integration::KIND_CALENDAR,
            'status' => Integration::STATUS_ACTIVE,
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'external_calendar_id' => 'primary@example.test',
        ]);
    }
}
