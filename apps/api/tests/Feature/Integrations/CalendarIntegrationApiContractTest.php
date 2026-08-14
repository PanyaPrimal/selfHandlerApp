<?php

namespace Tests\Feature\Integrations;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class CalendarIntegrationApiContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_operations_exist_and_have_unique_operation_ids(): void
    {
        $document = Yaml::parseFile(base_path('../../specs/025-calendar-integration/contracts/openapi.yaml'));
        $registered = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/'))
            ->flatMap(fn ($route) => collect($route->methods())->map(
                fn (string $method): string => strtoupper($method).' /'.preg_replace('#^api/?#', '', $route->uri()),
            ))->all();
        $operations = [];

        foreach ($document['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $this->assertContains(strtoupper($method).' '.$path, $registered);
                $operations[] = $operation['operationId'];
            }
        }

        $this->assertSame($operations, array_values(array_unique($operations)));
    }

    public function test_list_is_authenticated_owner_scoped_masked_and_closed(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        foreach ([$owner, $foreign] as $user) {
            Integration::query()->create([
                'user_id' => $user->id,
                'provider' => Integration::PROVIDER_GOOGLE,
                'kind' => Integration::KIND_CALENDAR,
                'status' => Integration::STATUS_PENDING,
                'external_account_label' => $user->email,
                'access_token' => 'access-'.$user->id,
                'refresh_token' => 'refresh-'.$user->id,
            ]);
        }

        $this->getJson('/api/integrations/calendars')->assertUnauthorized();

        $payload = $this->actingAs($owner)->getJson('/api/integrations/calendars')
            ->assertOk()->assertJsonCount(1, 'data')->json();
        $encoded = json_encode($payload);

        $this->assertStringNotContainsString($foreign->email, $encoded);
        $this->assertStringNotContainsString($owner->email, $encoded);
        $this->assertStringNotContainsString('access-', $encoded);
        $this->assertStringNotContainsString('refresh-', $encoded);
        $this->assertSame(
            ['data', 'providers'],
            array_keys($payload),
        );
    }

    public function test_foreign_settings_sync_and_disconnect_are_indistinguishable_from_missing(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $integration = Integration::query()->create([
            'user_id' => $owner->id,
            'provider' => Integration::PROVIDER_GOOGLE,
            'kind' => Integration::KIND_CALENDAR,
            'status' => Integration::STATUS_ACTIVE,
        ]);

        $this->actingAs($foreign);
        $this->patchJson("/api/integrations/calendars/{$integration->id}", [
            'import_detail' => Integration::IMPORT_TITLE,
        ])->assertNotFound();
        $this->postJson("/api/integrations/calendars/{$integration->id}/sync")->assertNotFound();
        $this->deleteJson("/api/integrations/calendars/{$integration->id}", [
            'confirmation' => 'DISCONNECT',
        ])->assertNotFound();
    }

    public function test_apple_connection_selection_settings_and_local_only_disconnect_follow_closed_contracts(): void
    {
        config()->set('integrations.apple.discovery_url', 'https://caldav.apple.test/.well-known/caldav');
        config()->set('integrations.apple.allowed_hosts', ['caldav.apple.test']);
        Http::preventStrayRequests();
        Http::fake([
            'https://caldav.apple.test/.well-known/caldav' => Http::response($this->propertyResponse(
                '<d:current-user-principal><d:href>/principal/</d:href></d:current-user-principal>',
            ), 207),
            'https://caldav.apple.test/principal/' => Http::response($this->propertyResponse(
                '<c:calendar-home-set><d:href>/calendars/</d:href></c:calendar-home-set>',
            ), 207),
            'https://caldav.apple.test/calendars/' => Http::response($this->calendarListResponse(), 207),
        ]);
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $connected = $this->postJson('/api/integrations/calendars/apple/connect', [
            'account' => 'Owner@Example.test',
            'app_specific_password' => 'abcd-efgh-ijkl-mnop',
        ])->assertCreated()
            ->assertJsonPath('data.account', 'o***@example.test')
            ->assertJsonPath('data.settings.export_categories', [])
            ->assertJsonCount(1, 'calendars');
        $integrationId = $connected->json('data.id');
        $rawSecret = DB::table('integrations')->where('id', $integrationId)->value('secret');
        $this->assertStringNotContainsString('abcd-efgh-ijkl-mnop', (string) $rawSecret);
        $this->assertStringNotContainsString('abcd-efgh-ijkl-mnop', $connected->getContent());

        $calendarId = 'https://caldav.apple.test/calendars/personal/';
        $this->putJson("/api/integrations/calendars/{$integrationId}/selection", [
            'calendar_id' => $calendarId,
        ])->assertOk()->assertJsonPath('data.status', Integration::STATUS_ACTIVE)
            ->assertJsonPath('data.calendar.name', 'Personal');
        $this->patchJson("/api/integrations/calendars/{$integrationId}", [
            'import_detail' => Integration::IMPORT_TITLE,
            'export_categories' => [Integration::EXPORT_FINANCE],
        ])->assertOk()->assertJsonPath('data.settings.import_detail', Integration::IMPORT_TITLE)
            ->assertJsonPath('data.settings.export_categories.0', Integration::EXPORT_FINANCE);
        $this->patchJson("/api/integrations/calendars/{$integrationId}", [
            'unexpected' => true,
        ])->assertUnprocessable();
        $this->deleteJson("/api/integrations/calendars/{$integrationId}", [
            'confirmation' => 'disconnect',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('integrations', ['id' => $integrationId]);
        $this->deleteJson("/api/integrations/calendars/{$integrationId}", [
            'confirmation' => 'DISCONNECT',
        ])->assertNoContent();
        $this->assertDatabaseMissing('integrations', ['id' => $integrationId]);
    }

    private function propertyResponse(string $property): string
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
}
