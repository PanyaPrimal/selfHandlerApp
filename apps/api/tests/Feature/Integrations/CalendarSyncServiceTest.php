<?php

namespace Tests\Feature\Integrations;

use App\Contracts\CalendarProvider;
use App\Data\Calendar\CalendarDescriptor;
use App\Data\Calendar\CalendarEventEnvelope;
use App\Data\Calendar\CalendarEventPage;
use App\Data\Calendar\CalendarWriteResult;
use App\Exceptions\CalendarIntegrationException;
use App\Models\ExternalCalendarEvent;
use App\Models\Integration;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\Routine;
use App\Models\SyncedItem;
use App\Models\TimeBlock;
use App\Models\User;
use App\Services\Integrations\CalendarProviderRegistry;
use App\Services\Integrations\CalendarSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class CalendarSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_pull_is_minimal_idempotent_and_zero_export_by_default(): void
    {
        [$owner, $integration] = $this->ownerAndIntegration();
        $provider = new InMemoryCalendarProvider([
            CalendarEventEnvelope::timed(
                'provider-event', 'Private meeting',
                CarbonImmutable::parse('2026-08-14T07:00:00Z'),
                CarbonImmutable::parse('2026-08-14T08:00:00Z'),
                'confirmed', 'etag-1', CarbonImmutable::parse('2026-08-14T06:00:00Z'),
            ),
        ]);
        $service = $this->service($provider);

        $first = $service->sync($integration);
        $second = $service->sync($integration->fresh());
        $third = $service->sync($integration->fresh());

        $this->assertSame(1, $first['imported']);
        $this->assertSame(0, $first['exported']);
        $this->assertSame(0, $provider->upserts);
        $this->assertSame(1, ExternalCalendarEvent::query()->ownedBy($owner)->count());
        $this->assertSame(1, SyncedItem::query()->ownedBy($owner)->where('origin', SyncedItem::ORIGIN_PROVIDER)->count());
        $this->assertSame(0, $second['imported']);
        $this->assertSame(0, $third['imported']);
        $this->assertSame('next-cursor', $integration->fresh()->sync_cursor);
        $this->assertNotSame(
            'Private meeting',
            DB::table('external_calendar_events')->value('summary'),
        );
    }

    public function test_time_block_export_is_explicit_stable_and_local_authoritative(): void
    {
        [$owner, $integration] = $this->ownerAndIntegration([
            'settings' => [
                ...Integration::defaultSettings(),
                'export_categories' => [Integration::EXPORT_TIME_BLOCK],
                'calendar_writable' => true,
            ],
        ]);
        $block = TimeBlock::query()->create([
            'user_id' => $owner->id,
            'title' => 'Dentist',
            'block_date' => '2026-08-14',
            'starts_at' => '10:00',
            'ends_at' => '11:00',
        ]);
        $provider = new InMemoryCalendarProvider;
        $service = $this->service($provider);

        $first = $service->sync($integration);
        $second = $service->sync($integration->fresh());
        $block->update(['title' => 'Dentist follow-up']);
        $third = $service->sync($integration->fresh());

        $mapping = SyncedItem::query()->where('local_type', SyncedItem::LOCAL_TIME_BLOCK)
            ->where('local_id', $block->id)->firstOrFail();
        $this->assertSame(1, $first['exported']);
        $this->assertSame(0, $second['exported']);
        $this->assertSame(1, $third['exported']);
        $this->assertSame(2, $provider->upserts);
        $this->assertSame('Dentist follow-up', $provider->events[$mapping->external_id]->summary);
        $this->assertSame(1, SyncedItem::query()->where('local_type', SyncedItem::LOCAL_TIME_BLOCK)->count());
    }

    public function test_provider_deletion_removes_only_imported_projection_and_disconnect_never_calls_provider(): void
    {
        [$owner, $integration] = $this->ownerAndIntegration();
        $provider = new InMemoryCalendarProvider([
            CalendarEventEnvelope::allDay(
                'external-holiday', 'Holiday', '2026-08-14', '2026-08-16',
                'confirmed', 'etag-1', null,
            ),
        ]);
        $service = $this->service($provider);
        $service->sync($integration);
        $provider->events['external-holiday'] = CalendarEventEnvelope::tombstone('external-holiday');

        $result = $service->sync($integration->fresh());

        $this->assertSame(1, $result['removed']);
        $this->assertSame(0, ExternalCalendarEvent::query()->ownedBy($owner)->count());
        $this->actingAs($owner)->deleteJson("/api/integrations/calendars/{$integration->id}", [
            'confirmation' => 'DISCONNECT',
        ])->assertNoContent();
        $this->assertSame(0, $provider->deletes);
        $this->assertDatabaseMissing('integrations', ['id' => $integration->id]);
    }

    public function test_remote_deletion_of_selfhandler_event_is_a_conflict_and_local_authority_republishes_it(): void
    {
        [$owner, $integration] = $this->ownerAndIntegration([
            'settings' => [
                ...Integration::defaultSettings(),
                'export_categories' => [Integration::EXPORT_TIME_BLOCK],
                'calendar_writable' => true,
            ],
        ]);
        $block = TimeBlock::query()->create([
            'user_id' => $owner->id,
            'title' => 'Keep locally',
            'block_date' => '2026-08-14',
            'starts_at' => '10:00',
            'ends_at' => '11:00',
        ]);
        $provider = new InMemoryCalendarProvider;
        $service = $this->service($provider);
        $service->sync($integration);
        $mapping = SyncedItem::query()->where('local_type', SyncedItem::LOCAL_TIME_BLOCK)
            ->where('local_id', $block->id)->firstOrFail();
        $provider->events[$mapping->external_id] = CalendarEventEnvelope::tombstone($mapping->external_id);

        $result = $service->sync($integration->fresh());

        $this->assertSame(1, $result['conflicts']);
        $this->assertSame(1, $result['exported']);
        $this->assertSame(2, $provider->upserts);
        $this->assertSame('Keep locally', $provider->events[$mapping->external_id]->summary);
        $this->assertDatabaseHas('synced_items', ['id' => $mapping->id]);
    }

    public function test_invalid_cursor_resets_only_provider_projection_and_retries_one_full_pull(): void
    {
        [$owner, $integration] = $this->ownerAndIntegration(['sync_cursor' => 'expired-cursor']);
        $provider = new InMemoryCalendarProvider([
            CalendarEventEnvelope::timed(
                'provider-event', 'Meeting',
                CarbonImmutable::parse('2026-08-14T07:00:00Z'),
                CarbonImmutable::parse('2026-08-14T08:00:00Z'),
                'confirmed', 'etag-1', null,
            ),
        ]);
        $provider->invalidateCursorOnce = true;

        $result = $this->service($provider)->sync($integration);

        $this->assertSame(['expired-cursor', null], $provider->pullCursors);
        $this->assertSame(1, $result['imported']);
        $this->assertSame('next-cursor', $integration->fresh()->sync_cursor);
        $this->assertSame(1, ExternalCalendarEvent::query()->ownedBy($owner)->count());
    }

    public function test_lock_and_auth_failure_are_closed_and_preserve_prior_cursor(): void
    {
        [, $integration] = $this->ownerAndIntegration(['sync_cursor' => 'stable-cursor']);
        $provider = new InMemoryCalendarProvider;
        $service = $this->service($provider);
        $lock = Cache::lock('calendar:integration:'.$integration->id, 300);
        $this->assertTrue($lock->get());
        try {
            $service->sync($integration);
            $this->fail('A concurrent sync acquired the same lock.');
        } catch (CalendarIntegrationException $exception) {
            $this->assertSame('calendar_sync_busy', $exception->errorCode);
        } finally {
            $lock->release();
        }

        $provider->failure = CalendarIntegrationException::auth();
        try {
            $service->sync($integration->fresh());
            $this->fail('Provider authentication failure was accepted.');
        } catch (CalendarIntegrationException $exception) {
            $this->assertSame('calendar_auth_expired', $exception->errorCode);
        }
        $this->assertSame(Integration::STATUS_EXPIRED, $integration->fresh()->status);
        $this->assertSame('stable-cursor', $integration->fresh()->sync_cursor);
    }

    public function test_selected_routine_occurrence_is_projected_once_with_stable_identity(): void
    {
        [$owner, $integration] = $this->ownerAndIntegration([
            'settings' => [
                ...Integration::defaultSettings(),
                'export_categories' => [Integration::EXPORT_ROUTINE],
                'calendar_writable' => true,
            ],
        ]);
        $routine = Routine::query()->create([
            'user_id' => $owner->id,
            'name' => 'Morning routine',
            'kind' => 'routine',
            'sort_order' => 1,
            'is_active' => true,
            'is_archived' => false,
        ]);
        $rule = RecurringRule::query()->create([
            'user_id' => $owner->id,
            'owner_type' => RecurringRule::OWNER_ROUTINE,
            'owner_id' => $routine->id,
            'frequency' => 'daily',
            'timezone' => 'Europe/Kyiv',
            'interval_count' => 1,
        ]);
        $occurrence = PlannedOccurrence::query()->create([
            'user_id' => $owner->id,
            'recurring_rule_id' => $rule->id,
            'occurrence_date' => '2026-08-14',
            'slot' => '',
            'occurrence_time' => '08:30',
            'status' => PlannedOccurrence::STATUS_PLANNED,
        ]);
        $provider = new InMemoryCalendarProvider;
        $service = $this->service($provider);

        $first = $service->sync($integration);
        $second = $service->sync($integration->fresh());

        $mapping = SyncedItem::query()->where('local_type', SyncedItem::LOCAL_PLANNED_OCCURRENCE)
            ->where('local_id', $occurrence->id)->firstOrFail();
        $this->assertSame(1, $first['exported']);
        $this->assertSame(0, $second['exported']);
        $this->assertSame('Morning routine', $provider->events[$mapping->external_id]->summary);
    }

    private function service(InMemoryCalendarProvider $provider): CalendarSyncService
    {
        $registry = Mockery::mock(CalendarProviderRegistry::class);
        $registry->shouldReceive('for')->andReturn($provider);

        return app()->makeWith(CalendarSyncService::class, ['providers' => $registry]);
    }

    /** @return array{User,Integration} */
    private function ownerAndIntegration(array $overrides = []): array
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
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            ...$overrides,
        ]);

        return [$owner, $integration];
    }
}

class InMemoryCalendarProvider implements CalendarProvider
{
    /** @var array<string,CalendarEventEnvelope> */
    public array $events = [];

    public int $upserts = 0;

    public int $deletes = 0;

    public bool $invalidateCursorOnce = false;

    public ?CalendarIntegrationException $failure = null;

    /** @var list<?string> */
    public array $pullCursors = [];

    /** @param list<CalendarEventEnvelope> $events */
    public function __construct(array $events = [])
    {
        foreach ($events as $event) {
            $this->events[$event->externalId] = $event;
        }
    }

    public function provider(): string
    {
        return Integration::PROVIDER_GOOGLE;
    }

    public function configured(): bool
    {
        return true;
    }

    public function calendars(Integration $integration): array
    {
        return [new CalendarDescriptor('primary', 'Personal', 'Europe/Kyiv', true, true)];
    }

    public function pull(Integration $integration, CarbonImmutable $from, CarbonImmutable $to, ?string $cursor): CalendarEventPage
    {
        $this->pullCursors[] = $cursor;
        if ($this->failure) {
            throw $this->failure;
        }
        if ($this->invalidateCursorOnce) {
            $this->invalidateCursorOnce = false;
            throw CalendarIntegrationException::cursor();
        }

        return new CalendarEventPage(array_values($this->events), 'next-cursor', true);
    }

    public function upsert(
        Integration $integration,
        CalendarEventEnvelope $event,
        string $stableId,
        ?string $externalId,
        ?string $etag,
    ): CalendarWriteResult {
        $this->upserts++;
        $id = $externalId ?? 'remote-'.$stableId;
        $this->events[$id] = $event->allDay
            ? CalendarEventEnvelope::allDay($id, $event->summary, $event->startDate, $event->endDate,
                'confirmed', 'etag-'.$this->upserts, now()->toImmutable(), $stableId)
            : CalendarEventEnvelope::timed($id, $event->summary, $event->startsAt, $event->endsAt,
                'confirmed', 'etag-'.$this->upserts, now()->toImmutable(), $stableId);

        return new CalendarWriteResult($id, 'etag-'.$this->upserts, now()->toImmutable());
    }

    public function delete(Integration $integration, string $externalId, ?string $etag): void
    {
        $this->deletes++;
        unset($this->events[$externalId]);
    }
}
