<?php

namespace Tests\Feature\Recurrence;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The cutover moves live schedule data; it must not lose any of it.
 *
 * The migration itself has already run by the time a test executes, so these
 * assertions cover its outcome — the shape it leaves behind and the rules it
 * produces for routines created afterwards. The data-bearing rehearsal against a
 * disposable database is part of the completion gate, not of this suite.
 */
class RecurrenceMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_old_schedule_shape_is_gone(): void
    {
        $this->assertFalse(
            Schema::hasTable('routine_weekdays'),
            'The routine weekday table must not survive the cutover.',
        );

        foreach (['schedule_type', 'preferred_time', 'starts_on', 'ends_on'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('routines', $column),
                "routines.{$column} must not survive the cutover.",
            );
        }
    }

    public function test_the_new_schedule_shape_exists_with_its_ownership_columns(): void
    {
        $this->assertTrue(Schema::hasTable('recurring_rules'));
        $this->assertTrue(Schema::hasTable('recurring_rule_weekdays'));
        $this->assertTrue(Schema::hasTable('planned_occurrences'));

        foreach ([
            'recurring_rules' => ['user_id', 'owner_type', 'owner_id', 'frequency', 'starts_on', 'ends_on', 'timezone', 'slot_time', 'last_materialized_until'],
            'recurring_rule_weekdays' => ['user_id', 'recurring_rule_id', 'weekday'],
            'planned_occurrences' => ['user_id', 'recurring_rule_id', 'occurrence_date', 'slot', 'occurrence_time', 'status', 'routine_log_id', 'materialized_at'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "{$table}.{$column} is missing.",
                );
            }
        }
    }
}
