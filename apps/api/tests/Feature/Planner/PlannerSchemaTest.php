<?php

namespace Tests\Feature\Planner;

use App\Models\PlannedOccurrence;
use App\Models\TimeBlock;
use Illuminate\Support\Facades\Schema;

class PlannerSchemaTest extends PlannerTestCase
{
    public function test_the_planner_adds_tables_without_reshaping_existing_ones(): void
    {
        $this->assertTrue(Schema::hasTable('time_blocks'));

        $this->assertTrue(Schema::hasColumns('time_blocks', [
            'id', 'user_id', 'title', 'note', 'block_date', 'starts_at', 'ends_at', 'created_at', 'updated_at',
        ]));

        // `rescheduled_to` is added beside the expanded date, never in place of
        // it, so a move never destroys what the rule originally produced.
        $this->assertTrue(Schema::hasColumn('planned_occurrences', 'occurrence_date'));
        $this->assertTrue(Schema::hasColumn('planned_occurrences', 'rescheduled_to'));
    }

    public function test_a_day_that_was_never_moved_stays_null(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);

        // Every occurrence the engine already wrote keeps working untouched:
        // the new column is nullable and defaults to "not moved".
        $this->assertGreaterThan(0, PlannedOccurrence::query()->count());
        $this->assertSame(0, PlannedOccurrence::query()->whereNotNull('rescheduled_to')->count());

        $occurrence = $this->occurrenceOn($routine, self::TODAY);
        $this->assertNull($occurrence->rescheduled_to);
    }

    public function test_identifier_names_fit_within_the_database_limit(): void
    {
        // MySQL refuses identifiers over 64 characters while SQLite accepts them,
        // so a name that only breaks in production must break here first.
        foreach (['time_blocks', 'planned_occurrences'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(
                    64,
                    strlen($index['name']),
                    "Index {$index['name']} on {$table} exceeds the 64-character limit.",
                );
            }
        }
    }

    public function test_a_block_belongs_to_one_account_and_cannot_change_hands(): void
    {
        $owner = $this->createUser('owner@example.test');
        $other = $this->createUser('other@example.test');

        $block = $this->createBlock($owner);

        $this->assertSame($owner->id, $block->user_id);
        $this->assertSame(1, TimeBlock::query()->ownedBy($owner)->count());
        $this->assertSame(0, TimeBlock::query()->ownedBy($other)->count());

        $block->user_id = $other->id;

        $this->expectException(\RuntimeException::class);
        $block->save();
    }

    public function test_a_block_refuses_to_be_saved_without_an_owner(): void
    {
        $this->expectException(\RuntimeException::class);

        TimeBlock::create([
            'title' => 'Ownerless',
            'block_date' => self::TODAY,
        ]);
    }

    public function test_deleting_an_account_takes_its_blocks_with_it(): void
    {
        $owner = $this->createUser();
        $this->createBlock($owner);

        $owner->delete();

        // No orphan rows are left holding a user id that no longer exists.
        $this->assertSame(0, TimeBlock::query()->count());
    }
}
