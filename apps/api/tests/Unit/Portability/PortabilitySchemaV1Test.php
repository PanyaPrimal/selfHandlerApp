<?php

namespace Tests\Unit\Portability;

use App\Services\Portability\PortabilitySchemaV1;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortabilitySchemaV1Test extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_covers_every_authoritative_owned_table_and_only_deliberate_exclusions(): void
    {
        $all = collect(DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"))
            ->pluck('name')
            ->filter(fn (string $table): bool => collect(DB::select("pragma table_info('{$table}')"))
                ->contains(fn (object $column): bool => $column->name === 'user_id'))
            ->sort()->values()->all();
        $expectedExclusions = ['attachments', 'external_calendar_events', 'integrations', 'notification_settings',
            'notifications', 'sessions', 'synced_items', 'user_profiles'];

        $this->assertSame($expectedExclusions, PortabilitySchemaV1::excludedOwnedTables());
        $this->assertSame(
            array_values(array_diff($all, $expectedExclusions)),
            collect(PortabilitySchemaV1::tables())->keys()->sort()->values()->all(),
        );
    }

    public function test_every_table_field_is_explicitly_classified(): void
    {
        foreach (PortabilitySchemaV1::tables() as $table => $definition) {
            $columns = collect(DB::select("pragma table_info('{$table}')"))->pluck('name')->all();
            $classified = ['id', 'user_id', ...$definition['attributes'], ...array_keys($definition['references'])];

            sort($columns);
            $classified = array_values(array_unique($classified));
            sort($classified);
            $this->assertSame($columns, $classified, "Unclassified columns in {$table}");
        }
    }
}
