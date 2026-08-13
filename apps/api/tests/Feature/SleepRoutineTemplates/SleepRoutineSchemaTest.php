<?php

namespace Tests\Feature\SleepRoutineTemplates;

use App\Models\Routine;
use App\Models\RoutineActivity;
use App\Models\RoutineActivityLog;
use App\Models\RoutineDaySelection;
use App\Models\SleepLog;
use App\Models\SleepOccurrenceDetail;
use App\Models\SleepPlan;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SleepRoutineSchemaTest extends SleepRoutineTestCase
{
    public function test_additive_tables_columns_and_defaults_exist(): void
    {
        foreach ([
            'sleep_plans' => [
                'id', 'user_id', 'name', 'planned_wake_time', 'is_active', 'is_archived',
                'archived_at', 'created_at', 'updated_at',
            ],
            'sleep_occurrence_details' => [
                'id', 'user_id', 'planned_occurrence_id', 'planned_wake_time', 'created_at', 'updated_at',
            ],
            'sleep_logs' => [
                'id', 'user_id', 'sleep_plan_id', 'sleep_date', 'actual_bed_at', 'actual_wake_at',
                'quality', 'note', 'created_at', 'updated_at',
            ],
            'routine_activities' => [
                'id', 'user_id', 'routine_id', 'name', 'sort_order', 'preferred_time',
                'progress_total', 'created_at', 'updated_at',
            ],
            'routine_activity_logs' => [
                'id', 'user_id', 'routine_activity_id', 'log_date', 'status', 'progress_value',
                'note', 'completed_at', 'created_at', 'updated_at',
            ],
            'routine_day_selections' => [
                'id', 'user_id', 'selection_date', 'period', 'routine_id', 'created_at', 'updated_at',
            ],
        ] as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertTrue(Schema::hasColumns($table, $columns), $table);
        }

        $this->assertTrue(Schema::hasColumn('routines', 'day_period'));
        $this->assertTrue(Schema::hasColumn('planned_occurrences', 'sleep_log_id'));

        $owner = $this->createUser();
        $routine = Routine::create(['user_id' => $owner->id, 'name' => 'Legacy simple routine']);
        $this->assertSame(Routine::DAY_PERIOD_ANYTIME, $routine->fresh()->day_period);
    }

    public function test_unique_constraints_protect_occurrence_details_facts_orders_and_selections(): void
    {
        $owner = $this->createUser();
        $plan = $this->createSleepPlan($owner);
        $occurrence = $this->sleepOccurrenceOn($plan);

        $this->expectException(UniqueConstraintViolationException::class);
        SleepOccurrenceDetail::create([
            'user_id' => $owner->id,
            'planned_occurrence_id' => $occurrence->id,
            'planned_wake_time' => '08:00',
        ]);
    }

    public function test_domain_uniques_reject_duplicate_sleep_dates_activity_dates_orders_and_periods(): void
    {
        $owner = $this->createUser();
        $plan = $this->createSleepPlan($owner);
        $routine = $this->createRoutine($owner);
        $activity = $this->createActivity($routine);

        SleepLog::create([
            'user_id' => $owner->id,
            'sleep_plan_id' => $plan->id,
            'sleep_date' => self::TODAY,
            'actual_bed_at' => self::TODAY.' 23:00:00',
            'actual_wake_at' => self::TOMORROW.' 07:00:00',
            'quality' => 8,
        ]);
        RoutineActivityLog::create([
            'user_id' => $owner->id,
            'routine_activity_id' => $activity->id,
            'log_date' => self::TODAY,
            'status' => RoutineActivityLog::STATUS_DONE,
        ]);
        RoutineDaySelection::create([
            'user_id' => $owner->id,
            'selection_date' => self::TODAY,
            'period' => Routine::DAY_PERIOD_MORNING,
            'routine_id' => $routine->id,
        ]);

        foreach ([
            fn () => SleepLog::create([
                'user_id' => $owner->id,
                'sleep_plan_id' => $plan->id,
                'sleep_date' => self::TODAY,
                'actual_bed_at' => self::TODAY.' 22:00:00',
                'actual_wake_at' => self::TOMORROW.' 06:00:00',
                'quality' => 7,
            ]),
            fn () => RoutineActivityLog::create([
                'user_id' => $owner->id,
                'routine_activity_id' => $activity->id,
                'log_date' => self::TODAY,
                'status' => RoutineActivityLog::STATUS_SKIPPED,
            ]),
            fn () => RoutineActivity::create([
                'user_id' => $owner->id,
                'routine_id' => $routine->id,
                'name' => 'Duplicate order',
                'sort_order' => 0,
            ]),
            fn () => RoutineDaySelection::create([
                'user_id' => $owner->id,
                'selection_date' => self::TODAY,
                'period' => Routine::DAY_PERIOD_MORNING,
                'routine_id' => null,
            ]),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected a unique constraint violation.');
            } catch (UniqueConstraintViolationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_hard_routine_delete_cascades_linked_selection_but_explicit_none_survives(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        RoutineDaySelection::create([
            'user_id' => $owner->id,
            'selection_date' => self::TODAY,
            'period' => Routine::DAY_PERIOD_MORNING,
            'routine_id' => $routine->id,
        ]);
        RoutineDaySelection::create([
            'user_id' => $owner->id,
            'selection_date' => self::TODAY,
            'period' => Routine::DAY_PERIOD_EVENING,
            'routine_id' => null,
        ]);

        $routine->forceDelete();

        $this->assertDatabaseMissing('routine_day_selections', [
            'selection_date' => self::TODAY,
            'period' => Routine::DAY_PERIOD_MORNING,
        ]);
        $this->assertDatabaseHas('routine_day_selections', [
            'selection_date' => self::TODAY,
            'period' => Routine::DAY_PERIOD_EVENING,
            'routine_id' => null,
        ]);
    }

    public function test_user_deletion_cascades_every_feature_table(): void
    {
        $owner = $this->createUser();
        $plan = $this->createSleepPlan($owner);
        $routine = $this->createRoutine($owner);
        $activity = $this->createActivity($routine);
        RoutineActivityLog::create([
            'user_id' => $owner->id,
            'routine_activity_id' => $activity->id,
            'log_date' => self::TODAY,
            'status' => RoutineActivityLog::STATUS_DONE,
        ]);
        RoutineDaySelection::create([
            'user_id' => $owner->id,
            'selection_date' => self::TODAY,
            'period' => Routine::DAY_PERIOD_MORNING,
            'routine_id' => $routine->id,
        ]);

        $owner->delete();

        foreach ([SleepPlan::class, SleepOccurrenceDetail::class, SleepLog::class,
            RoutineActivity::class, RoutineActivityLog::class, RoutineDaySelection::class] as $model) {
            $this->assertSame(0, $model::query()->count(), $model);
        }
    }

    public function test_migration_rolls_back_only_feature_014_and_preserves_existing_rows(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $migration = require database_path('migrations/2026_08_13_180000_create_sleep_and_routine_templates.php');

        $migration->down();

        $this->assertFalse(Schema::hasTable('sleep_plans'));
        $this->assertFalse(Schema::hasTable('routine_activities'));
        $this->assertFalse(Schema::hasColumn('routines', 'day_period'));
        $this->assertFalse(Schema::hasColumn('planned_occurrences', 'sleep_log_id'));
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertDatabaseHas('routines', ['id' => $routine->id]);

        $migration->up();

        $this->assertTrue(Schema::hasTable('sleep_plans'));
        $this->assertSame('anytime', DB::table('routines')->where('id', $routine->id)->value('day_period'));
    }

    public function test_every_new_index_name_is_mysql_safe(): void
    {
        foreach ([
            'sleep_plans', 'sleep_occurrence_details', 'sleep_logs', 'routine_activities',
            'routine_activity_logs', 'routine_day_selections', 'routines', 'planned_occurrences',
        ] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), $index['name']);
            }
        }
    }
}
