<?php

namespace Tests\Feature\Integrations;

use App\Models\ExternalCalendarEvent;
use App\Models\Integration;
use App\Models\SyncedItem;
use App\Models\TimeBlock;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class CalendarIntegrationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_tables_are_additive_owned_and_indexed(): void
    {
        foreach (['integrations', 'external_calendar_events', 'synced_items'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'user_id'), "{$table} must be owner scoped.");
        }

        $this->assertTrue(Schema::hasColumns('integrations', [
            'provider', 'kind', 'status', 'external_account_label', 'external_calendar_id',
            'access_token', 'refresh_token', 'secret', 'sync_cursor', 'settings', 'last_success_at',
        ]));
        $this->assertTrue(Schema::hasColumns('external_calendar_events', [
            'integration_id', 'external_id_hash', 'summary', 'starts_at', 'ends_at',
            'start_date', 'end_date', 'is_all_day',
        ]));
        $this->assertTrue(Schema::hasColumns('synced_items', [
            'integration_id', 'origin', 'local_type', 'local_id', 'external_id',
            'external_id_hash', 'external_etag', 'local_fingerprint', 'last_synced_at',
        ]));
    }

    public function test_integration_secrets_are_encrypted_hidden_and_settings_default_closed(): void
    {
        $owner = User::factory()->create();
        $integration = Integration::query()->create([
            'user_id' => $owner->id,
            'provider' => Integration::PROVIDER_GOOGLE,
            'kind' => Integration::KIND_CALENDAR,
            'status' => Integration::STATUS_PENDING,
            'external_account_label' => 'owner@example.test',
            'access_token' => 'plain-access',
            'refresh_token' => 'plain-refresh',
            'sync_cursor' => 'plain-cursor',
        ]);

        $raw = DB::table('integrations')->where('id', $integration->id)->first();
        foreach ([
            'external_account_label' => 'owner@example.test',
            'access_token' => 'plain-access',
            'refresh_token' => 'plain-refresh',
            'sync_cursor' => 'plain-cursor',
        ] as $field => $plain) {
            $this->assertNotSame($plain, $raw->{$field});
            $this->assertStringNotContainsString($plain, (string) $raw->{$field});
        }
        $this->assertStringNotContainsString('plain-access', json_encode($integration));
        $this->assertStringNotContainsString('owner@example.test', json_encode($integration));
        $this->assertSame([
            'import_detail' => Integration::IMPORT_BUSY_ONLY,
            'export_categories' => [],
            'calendar_writable' => false,
            'calendar_timezone' => null,
        ], $integration->settings);
    }

    public function test_same_owner_guards_cover_imported_events_and_mappings(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $integration = Integration::query()->create([
            'user_id' => $owner->id,
            'provider' => Integration::PROVIDER_APPLE,
            'kind' => Integration::KIND_CALENDAR,
            'status' => Integration::STATUS_ACTIVE,
        ]);

        $this->expectException(LogicException::class);
        ExternalCalendarEvent::query()->create([
            'user_id' => $foreign->id,
            'integration_id' => $integration->id,
            'external_id_hash' => hash('sha256', 'foreign'),
            'summary' => 'Private meeting',
            'starts_at' => '2026-08-14 10:00:00',
            'ends_at' => '2026-08-14 11:00:00',
            'is_all_day' => false,
            'status' => ExternalCalendarEvent::STATUS_CONFIRMED,
        ]);
    }

    public function test_mapping_rejects_a_foreign_local_fact(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $integration = Integration::query()->create([
            'user_id' => $owner->id,
            'provider' => Integration::PROVIDER_GOOGLE,
            'kind' => Integration::KIND_CALENDAR,
            'status' => Integration::STATUS_ACTIVE,
        ]);
        $block = TimeBlock::query()->create([
            'user_id' => $foreign->id,
            'title' => 'Foreign',
            'block_date' => '2026-08-14',
            'starts_at' => '10:00',
            'ends_at' => '11:00',
        ]);

        $this->expectException(LogicException::class);
        SyncedItem::query()->create([
            'user_id' => $owner->id,
            'integration_id' => $integration->id,
            'origin' => SyncedItem::ORIGIN_SELFHANDLER,
            'local_type' => SyncedItem::LOCAL_TIME_BLOCK,
            'local_id' => $block->id,
            'external_id' => 'provider-id',
            'external_id_hash' => hash('sha256', 'provider-id'),
        ]);
    }

    public function test_one_provider_connection_per_owner_does_not_make_accounts_globally_unique(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        foreach ([$first, $second] as $user) {
            Integration::query()->create([
                'user_id' => $user->id,
                'provider' => Integration::PROVIDER_GOOGLE,
                'kind' => Integration::KIND_CALENDAR,
                'status' => Integration::STATUS_PENDING,
                'external_account_id' => 'same-google-subject',
            ]);
        }

        $this->expectException(QueryException::class);
        Integration::query()->create([
            'user_id' => $first->id,
            'provider' => Integration::PROVIDER_GOOGLE,
            'kind' => Integration::KIND_CALENDAR,
            'status' => Integration::STATUS_PENDING,
        ]);
    }

    public function test_all_day_and_timed_events_are_mutually_exclusive(): void
    {
        $owner = User::factory()->create();
        $integration = Integration::query()->create([
            'user_id' => $owner->id,
            'provider' => Integration::PROVIDER_GOOGLE,
            'kind' => Integration::KIND_CALENDAR,
            'status' => Integration::STATUS_ACTIVE,
        ]);

        $this->expectException(LogicException::class);
        ExternalCalendarEvent::query()->create([
            'user_id' => $owner->id,
            'integration_id' => $integration->id,
            'external_id_hash' => hash('sha256', 'invalid'),
            'summary' => 'Invalid mixed shape',
            'starts_at' => '2026-08-14 10:00:00',
            'ends_at' => '2026-08-14 11:00:00',
            'start_date' => '2026-08-14',
            'end_date' => '2026-08-15',
            'is_all_day' => true,
            'status' => ExternalCalendarEvent::STATUS_CONFIRMED,
        ]);
    }

    public function test_calendar_migration_rolls_back_only_025_and_preserves_prior_domain_rows(): void
    {
        $owner = User::factory()->create();
        $block = TimeBlock::query()->create([
            'user_id' => $owner->id,
            'title' => 'Preserved block',
            'block_date' => '2026-08-14',
            'starts_at' => '10:00',
            'ends_at' => '11:00',
        ]);
        $migration = require database_path('migrations/2026_08_14_080000_create_calendar_integrations.php');

        $migration->down();

        $this->assertFalse(Schema::hasTable('integrations'));
        $this->assertFalse(Schema::hasTable('external_calendar_events'));
        $this->assertFalse(Schema::hasTable('synced_items'));
        $this->assertDatabaseHas('time_blocks', ['id' => $block->id, 'title' => 'Preserved block']);

        $migration->up();
        $this->assertTrue(Schema::hasTable('integrations'));
        $this->assertDatabaseHas('time_blocks', ['id' => $block->id, 'title' => 'Preserved block']);
    }
}
