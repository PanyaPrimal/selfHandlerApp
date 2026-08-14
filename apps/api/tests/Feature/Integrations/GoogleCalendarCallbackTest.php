<?php

namespace Tests\Feature\Integrations;

use App\Models\Integration;
use App\Models\User;
use App\Services\Integrations\GoogleOAuthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarCallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('integrations.web_settings_url', 'https://selfhandler.test/settings/integrations');
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

    public function test_callback_consumes_state_stores_encrypted_tokens_and_returns_only_closed_result_codes(): void
    {
        $owner = User::factory()->create();
        $issued = app(GoogleOAuthState::class)->issue($owner);
        Http::fake([
            'https://oauth2.google.test/token' => Http::response([
                'access_token' => 'private-access-token',
                'refresh_token' => 'private-refresh-token',
                'expires_in' => 3600,
            ]),
            'https://calendar.google.test/calendar/v3/users/me/calendarList*' => Http::response([
                'items' => [[
                    'id' => 'owner@example.test',
                    'summary' => 'Primary',
                    'timeZone' => 'Europe/Kyiv',
                    'accessRole' => 'owner',
                    'primary' => true,
                ]],
            ]),
        ]);

        $callback = '/api/integrations/calendars/google/callback?state='.urlencode($issued['state']).'&code=one-time-code';
        $this->get($callback)->assertRedirect(
            'https://selfhandler.test/settings/integrations?calendar=oauth_connected',
        );

        $integration = Integration::query()->ownedBy($owner)->firstOrFail();
        $this->assertSame(Integration::STATUS_PENDING, $integration->status);
        $this->assertSame('private-access-token', $integration->access_token);
        $this->assertSame('private-refresh-token', $integration->refresh_token);
        $this->assertSame('owner@example.test', $integration->external_account_label);
        $raw = DB::table('integrations')->where('id', $integration->id)->firstOrFail();
        $this->assertStringNotContainsString('private-access-token', $raw->access_token);
        $this->assertStringNotContainsString('private-refresh-token', $raw->refresh_token);
        $this->assertStringNotContainsString('owner@example.test', $raw->external_account_label);

        $this->get($callback)->assertRedirect(
            'https://selfhandler.test/settings/integrations?calendar=oauth_invalid_state',
        );
        $this->assertSame(1, Integration::query()->ownedBy($owner)->count());
    }

    public function test_denied_callback_consumes_state_without_creating_a_connection(): void
    {
        $owner = User::factory()->create();
        $issued = app(GoogleOAuthState::class)->issue($owner);

        $this->get('/api/integrations/calendars/google/callback?state='.urlencode($issued['state']).'&error=access_denied')
            ->assertRedirect('https://selfhandler.test/settings/integrations?calendar=oauth_denied');

        $this->assertDatabaseMissing('integrations', ['user_id' => $owner->id]);
        $this->get('/api/integrations/calendars/google/callback?state='.urlencode($issued['state']).'&error=access_denied')
            ->assertRedirect('https://selfhandler.test/settings/integrations?calendar=oauth_invalid_state');
    }
}
