<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationLifecycleTest extends TestCase
{
    use DatabaseMigrations;

    public function test_all_migrations_roll_back_and_reapply_on_the_selected_database_engine(): void
    {
        $tables = Schema::getTableListing(schemaQualified: false);
        sort($tables);

        $this->artisan('migrate:reset', ['--force' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('users'));
        $this->assertFalse(Schema::hasTable('planned_occurrences'));

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $restored = Schema::getTableListing(schemaQualified: false);
        sort($restored);
        $this->assertSame($tables, $restored);
        $this->assertDatabaseCount('currencies', 3);
    }
}
