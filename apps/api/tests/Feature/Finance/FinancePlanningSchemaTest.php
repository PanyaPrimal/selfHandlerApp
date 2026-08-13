<?php

namespace Tests\Feature\Finance;

use App\Models\FinanceBudgetLimit;
use App\Models\FinanceOccurrenceDetail;
use App\Models\FinanceOccurrenceFact;
use App\Models\FinanceRecurringOperation;
use App\Models\RecurringRuleMonthday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinancePlanningSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_planning_tables_and_occurrence_link_have_the_closed_shape(): void
    {
        foreach ([
            'finance_budget_limits' => ['id', 'user_id', 'category_id', 'budget_month', 'limit_amount', 'currency_code'],
            'finance_recurring_operations' => ['id', 'user_id', 'name', 'direction', 'account_id',
                'category_id', 'amount', 'currency_code', 'is_mandatory', 'is_active', 'is_archived', 'archived_at'],
            'recurring_rule_monthdays' => ['id', 'user_id', 'recurring_rule_id', 'monthday'],
            'finance_occurrence_details' => ['id', 'user_id', 'planned_occurrence_id',
                'finance_recurring_operation_id', 'operation_name', 'direction', 'account_id', 'category_id',
                'amount', 'currency_code', 'is_mandatory'],
            'finance_occurrence_facts' => ['id', 'user_id', 'planned_occurrence_id', 'outcome',
                'transaction_group_id', 'occurred_on'],
        ] as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertTrue(Schema::hasColumns($table, $columns), $table);
        }

        $this->assertTrue(Schema::hasColumn('planned_occurrences', 'finance_occurrence_fact_id'));
    }

    public function test_every_019_table_index_name_is_mysql_safe(): void
    {
        foreach (['finance_budget_limits', 'finance_recurring_operations', 'recurring_rule_monthdays',
            'finance_occurrence_details', 'finance_occurrence_facts', 'planned_occurrences'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), $index['name']);
            }
        }
    }

    public function test_every_private_019_entity_has_an_owner_safe_factory(): void
    {
        foreach ([
            FinanceBudgetLimit::factory()->create(),
            FinanceRecurringOperation::factory()->create(),
            RecurringRuleMonthday::factory()->create(),
            FinanceOccurrenceDetail::factory()->create(),
            FinanceOccurrenceFact::factory()->create(),
        ] as $model) {
            $this->assertNotNull($model->getKey(), $model::class);
            $this->assertNotNull($model->user_id, $model::class);
        }
    }
}
