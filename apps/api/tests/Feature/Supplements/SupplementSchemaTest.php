<?php

namespace Tests\Feature\Supplements;

use App\Models\RecurringRuleSlot;
use App\Models\Supplement;
use App\Models\SupplementCourse;
use App\Models\SupplementCourseSlot;
use App\Models\SupplementIntake;
use App\Models\SupplementRestockProposal;
use App\Models\SupplementStockMovement;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

class SupplementSchemaTest extends SupplementTestCase
{
    public function test_additive_tables_and_shared_columns_exist(): void
    {
        foreach ([
            'supplements' => ['id', 'user_id', 'name', 'category', 'form', 'stock_unit',
                'preferred_display_unit', 'usual_dose_quantity', 'package_quantity',
                'restock_lead_days', 'note', 'is_archived', 'archived_at'],
            'supplement_courses' => ['id', 'user_id', 'supplement_id', 'goal_id', 'name',
                'dose_quantity', 'dose_display_unit', 'starts_on', 'ends_on', 'is_active',
                'is_archived', 'archived_at'],
            'recurring_rule_slots' => ['id', 'user_id', 'recurring_rule_id', 'slot',
                'occurrence_time', 'sort_order'],
            'supplement_course_slots' => ['id', 'user_id', 'supplement_course_id',
                'recurring_rule_slot_id', 'intake_context'],
            'supplement_intakes' => ['id', 'user_id', 'supplement_course_id', 'supplement_id',
                'planned_on', 'effective_on', 'slot', 'outcome', 'dose_quantity',
                'dose_display_unit', 'supplement_name', 'taken_at', 'note'],
            'supplement_stock_movements' => ['id', 'user_id', 'supplement_id', 'kind',
                'quantity_delta', 'effective_on', 'reason', 'note'],
            'supplement_restock_proposals' => ['id', 'user_id', 'supplement_id',
                'active_supplement_id', 'shortage_fingerprint', 'forecast_runout_on', 'needed_by',
                'suggested_quantity', 'stock_unit', 'status', 'dismissed_at', 'resolved_at'],
        ] as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertTrue(Schema::hasColumns($table, $columns), $table);
        }

        $this->assertTrue(Schema::hasColumns('recurring_rules', [
            'interval_count', 'cycle_on_days', 'cycle_off_days',
        ]));
        $this->assertTrue(Schema::hasColumn('planned_occurrences', 'supplement_intake_id'));
    }

    public function test_every_new_index_name_is_mysql_safe(): void
    {
        foreach ([
            'supplements', 'supplement_courses', 'recurring_rule_slots', 'supplement_course_slots',
            'supplement_intakes', 'supplement_stock_movements', 'supplement_restock_proposals',
            'recurring_rules', 'planned_occurrences',
        ] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), $index['name']);
            }
        }
    }

    public function test_every_feature_entity_has_a_valid_owner_safe_factory(): void
    {
        foreach ([
            Supplement::factory()->create(),
            SupplementCourse::factory()->create(),
            RecurringRuleSlot::factory()->create(),
            SupplementCourseSlot::factory()->create(),
            SupplementIntake::factory()->create(),
            SupplementStockMovement::factory()->create(),
            SupplementRestockProposal::factory()->create(),
        ] as $model) {
            $this->assertNotNull($model->getKey(), $model::class);
            $this->assertNotNull($model->user_id, $model::class);
        }
    }

    public function test_hard_deletion_cannot_erase_facts_but_account_deletion_cascades_private_data(): void
    {
        $owner = $this->createUser();
        $supplement = $this->createSupplement($owner);
        $course = $this->createCourse($owner, $supplement);
        SupplementIntake::factory()->create([
            'user_id' => $owner->id,
            'supplement_course_id' => $course->id,
            'supplement_id' => $supplement->id,
        ]);
        SupplementStockMovement::factory()->create([
            'user_id' => $owner->id,
            'supplement_id' => $supplement->id,
        ]);

        try {
            $course->delete();
            $this->fail('A course with an intake fact must not be hard-deleted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('supplement_courses', ['id' => $course->id]);
            $this->assertDatabaseHas('supplement_intakes', ['supplement_course_id' => $course->id]);
        }

        try {
            $supplement->delete();
            $this->fail('A reference with historical facts must not be hard-deleted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('supplements', ['id' => $supplement->id]);
            $this->assertDatabaseHas('supplement_stock_movements', ['supplement_id' => $supplement->id]);
        }

        $owner->delete();
        foreach ([
            'supplements', 'supplement_courses', 'supplement_intakes',
            'supplement_stock_movements', 'recurring_rule_slots', 'supplement_course_slots',
        ] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
