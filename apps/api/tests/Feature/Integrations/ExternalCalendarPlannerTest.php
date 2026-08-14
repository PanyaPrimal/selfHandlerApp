<?php

namespace Tests\Feature\Integrations;

use App\Models\ExternalCalendarEvent;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExternalCalendarPlannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_busy_only_is_enforced_by_api_and_title_mode_is_owner_controlled(): void
    {
        [$owner, $integration] = $this->integration(Integration::IMPORT_BUSY_ONLY);
        $event = $this->timed($owner, $integration, 'Secret interview');
        $this->actingAs($owner);

        $response = $this->getJson('/api/planner/day?date=2026-08-14')->assertOk();
        $response->assertJsonPath('entries.0.source', 'external_calendar')
            ->assertJsonPath('entries.0.title', 'Busy')
            ->assertJsonPath('entries.0.actions', [])
            ->assertJsonMissing(['title' => 'Secret interview']);

        $integration->update(['settings' => [
            ...$integration->settings,
            'import_detail' => Integration::IMPORT_TITLE,
        ]]);
        $this->getJson('/api/planner/day?date=2026-08-14')->assertOk()
            ->assertJsonPath('entries.0.title', 'Secret interview')
            ->assertJsonPath('entries.0.source_id', $event->id);
    }

    public function test_timed_and_all_day_events_project_on_every_overlapping_profile_day(): void
    {
        [$owner, $integration] = $this->integration(Integration::IMPORT_TITLE);
        ExternalCalendarEvent::query()->create([
            'user_id' => $owner->id,
            'integration_id' => $integration->id,
            'external_id_hash' => hash('sha256', 'overnight'),
            'summary' => 'Overnight',
            'starts_at' => '2026-08-14 20:30:00',
            'ends_at' => '2026-08-15 01:30:00',
            'is_all_day' => false,
            'status' => ExternalCalendarEvent::STATUS_CONFIRMED,
        ]);
        ExternalCalendarEvent::query()->create([
            'user_id' => $owner->id,
            'integration_id' => $integration->id,
            'external_id_hash' => hash('sha256', 'holiday'),
            'summary' => 'Holiday',
            'start_date' => '2026-08-14',
            'end_date' => '2026-08-16',
            'is_all_day' => true,
            'status' => ExternalCalendarEvent::STATUS_CONFIRMED,
        ]);
        $this->actingAs($owner);

        $first = $this->getJson('/api/planner/day?date=2026-08-14')->assertOk()->json('entries');
        $second = $this->getJson('/api/planner/day?date=2026-08-15')->assertOk()->json('entries');
        $third = $this->getJson('/api/planner/day?date=2026-08-16')->assertOk()->json('entries');

        $this->assertSame(['Overnight', 'Holiday'], array_column($first, 'title'));
        $this->assertSame(['Overnight', 'Holiday'], array_column($second, 'title'));
        $this->assertSame([], $third);
        $this->assertSame('23:30', $first[0]['time']);
        $this->assertNull($first[1]['time']);
        $this->assertSame('00:00', $second[0]['time']);
    }

    private function integration(string $detail): array
    {
        $owner = User::factory()->create();
        $owner->ensureProfile()->forceFill(['timezone' => 'Europe/Kyiv'])->save();
        $integration = Integration::query()->create([
            'user_id' => $owner->id,
            'provider' => Integration::PROVIDER_GOOGLE,
            'kind' => Integration::KIND_CALENDAR,
            'status' => Integration::STATUS_ACTIVE,
            'external_calendar_id' => 'primary',
            'external_calendar_name' => 'Personal',
            'access_token' => 'access',
            'settings' => [...Integration::defaultSettings(), 'import_detail' => $detail],
        ]);

        return [$owner, $integration];
    }

    private function timed(User $owner, Integration $integration, string $summary): ExternalCalendarEvent
    {
        return ExternalCalendarEvent::query()->create([
            'user_id' => $owner->id,
            'integration_id' => $integration->id,
            'external_id_hash' => hash('sha256', $summary),
            'summary' => $summary,
            'starts_at' => '2026-08-14 07:00:00',
            'ends_at' => '2026-08-14 08:00:00',
            'is_all_day' => false,
            'status' => ExternalCalendarEvent::STATUS_CONFIRMED,
        ]);
    }
}
