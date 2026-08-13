<?php

namespace Tests\Feature\Habits;

use App\Models\Habit;
use App\Models\HabitLimitStep;
use App\Models\HabitLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

class HabitSchemaTest extends HabitTestCase
{
    public function test_additive_habit_tables_and_occurrence_fact_link_exist(): void
    {
        foreach ([
            'habits' => [
                'id', 'user_id', 'name', 'description', 'kind', 'mode', 'target_value', 'unit',
                'routine_id', 'goal_id', 'intention_place', 'two_minute_starter', 'is_active',
                'is_archived', 'archived_at', 'created_at', 'updated_at',
            ],
            'habit_logs' => [
                'id', 'user_id', 'habit_id', 'log_date', 'outcome', 'value', 'occurred_at', 'note',
                'created_at', 'updated_at',
            ],
            'habit_limit_steps' => [
                'id', 'user_id', 'habit_id', 'effective_on', 'limit_value', 'period', 'created_at',
                'updated_at',
            ],
        ] as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table));
            $this->assertTrue(Schema::hasColumns($table, $columns));
        }

        $this->assertTrue(Schema::hasColumn('planned_occurrences', 'routine_log_id'));
        $this->assertTrue(Schema::hasColumn('planned_occurrences', 'habit_log_id'));
    }

    public function test_uniqueness_prevents_duplicate_date_facts_steps_and_fact_links(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner);
        $log = HabitLog::create([
            'user_id' => $owner->id,
            'habit_id' => $habit->id,
            'log_date' => self::TODAY,
            'outcome' => HabitLog::OUTCOME_DONE,
            'occurred_at' => now(),
        ]);
        $this->occurrenceOn($habit)->update(['habit_log_id' => $log->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        HabitLog::create([
            'user_id' => $owner->id,
            'habit_id' => $habit->id,
            'log_date' => self::TODAY,
            'outcome' => HabitLog::OUTCOME_NOT_DONE,
            'occurred_at' => now(),
        ]);
    }

    public function test_target_deletion_nulls_links_and_user_deletion_cascades_domain_rows(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $goal = $this->createGoal($owner);
        $habit = $this->createHabit($owner, [
            'kind' => Habit::KIND_ANTI_HABIT,
            'mode' => Habit::MODE_STEPPED_LIMIT,
            'unit' => 'drinks',
            'routine_id' => $routine->id,
            'goal_id' => $goal->id,
        ]);
        HabitLimitStep::create([
            'user_id' => $owner->id,
            'habit_id' => $habit->id,
            'effective_on' => self::TODAY,
            'limit_value' => 1,
            'period' => HabitLimitStep::PERIOD_DAY,
        ]);

        $routine->forceDelete();
        $goal->forceDelete();

        $this->assertNull($habit->fresh()->routine_id);
        $this->assertNull($habit->fresh()->goal_id);

        $owner->delete();
        $this->assertSame(0, Habit::query()->count());
        $this->assertSame(0, HabitLimitStep::query()->count());
    }

    public function test_migration_rolls_back_only_013_and_preserves_existing_rows(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $goal = $this->createGoal($owner);
        $migration = require database_path('migrations/2026_08_13_160000_create_habits.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('habits'));
        $this->assertFalse(Schema::hasColumn('planned_occurrences', 'habit_log_id'));
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertDatabaseHas('routines', ['id' => $routine->id]);
        $this->assertDatabaseHas('goals', ['id' => $goal->id]);

        $migration->up();
        $this->assertTrue(Schema::hasTable('habits'));
        $this->assertTrue(Schema::hasColumn('planned_occurrences', 'habit_log_id'));
    }

    public function test_new_index_names_fit_mysql_identifier_limit(): void
    {
        foreach (['habits', 'habit_logs', 'habit_limit_steps', 'planned_occurrences'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), $index['name']);
            }
        }
    }
}
