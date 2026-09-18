<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Every schema identifier must fit MySQL's 64-character limit.
 *
 * SQLite has no such limit, so a name that
 * Laravel generates from a long table plus several long columns can pass every
 * test and then fail on the production database. This guard closes that gap
 * on both SQLite and MySQL using Laravel's schema inspection API.
 */
class SchemaIdentifierLengthTest extends TestCase
{
    use RefreshDatabase;

    private const MYSQL_IDENTIFIER_LIMIT = 64;

    public function test_no_table_or_index_name_exceeds_the_mysql_identifier_limit(): void
    {
        $tooLong = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $name) {
            if (strlen($name) > self::MYSQL_IDENTIFIER_LIMIT) {
                $tooLong[] = "table {$name} (".strlen($name).')';
            }
            foreach (Schema::getIndexes($name) as $index) {
                if (strlen($index['name']) > self::MYSQL_IDENTIFIER_LIMIT) {
                    $tooLong[] = "index {$index['name']} on {$name} (".strlen($index['name']).')';
                }
            }
        }

        $this->assertSame(
            [],
            $tooLong,
            'These identifiers are longer than MySQL allows. Name them explicitly in their migration.',
        );
    }
}
