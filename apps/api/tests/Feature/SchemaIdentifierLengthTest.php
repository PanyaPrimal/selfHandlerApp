<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Every schema identifier must fit MySQL's 64-character limit.
 *
 * The automated suite runs on SQLite, which has no such limit, so a name that
 * Laravel generates from a long table plus several long columns can pass every
 * test and then fail on the production database. This guard closes that gap
 * without needing MySQL: the names are read from the SQLite schema, which
 * Laravel generates identically.
 */
class SchemaIdentifierLengthTest extends TestCase
{
    use RefreshDatabase;

    private const MYSQL_IDENTIFIER_LIMIT = 64;

    public function test_no_table_or_index_name_exceeds_the_mysql_identifier_limit(): void
    {
        $tooLong = [];

        foreach (Schema::getTableListing() as $table) {
            $name = str_contains($table, '.') ? explode('.', $table)[1] : $table;

            if (strlen($name) > self::MYSQL_IDENTIFIER_LIMIT) {
                $tooLong[] = "table {$name} (".strlen($name).')';
            }
        }

        foreach (DB::select("SELECT name, tbl_name FROM sqlite_master WHERE type = 'index' AND name IS NOT NULL") as $index) {
            // Names SQLite generates for itself are not ours to control.
            if (str_starts_with($index->name, 'sqlite_autoindex_')) {
                continue;
            }

            if (strlen($index->name) > self::MYSQL_IDENTIFIER_LIMIT) {
                $tooLong[] = "index {$index->name} on {$index->tbl_name} (".strlen($index->name).')';
            }
        }

        $this->assertSame(
            [],
            $tooLong,
            'These identifiers are longer than MySQL allows. Name them explicitly in their migration.',
        );
    }
}
