<?php

namespace Tests\Feature\Finance;

use App\Models\FinanceBudgetLimit;
use App\Models\FinanceCounterparty;
use App\Models\FinanceGoalDetail;
use App\Models\FinanceSavingFund;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinanceCommitmentSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_feature_tables_and_shared_columns_are_additive(): void
    {
        foreach ([
            'finance_counterparties' => ['id', 'user_id', 'name', 'kind', 'note', 'is_archived', 'archived_at'],
            'finance_debts' => ['id', 'user_id', 'finance_counterparty_id', 'purchase_item_id', 'name',
                'direction', 'repayment_mode', 'original_amount', 'currency_code', 'originated_on',
                'deadline', 'account_id', 'category_id', 'installment_amount', 'installment_count',
                'interval_months', 'monthday', 'first_due_on', 'reminder_time', 'note', 'is_active',
                'is_archived', 'archived_at'],
            'finance_debt_occurrence_details' => ['id', 'user_id', 'planned_occurrence_id',
                'finance_debt_id', 'debt_name', 'direction', 'account_id', 'category_id', 'amount',
                'currency_code'],
            'finance_debt_payment_facts' => ['id', 'user_id', 'finance_debt_id',
                'planned_occurrence_id', 'transaction_group_id', 'principal_amount', 'currency_code',
                'occurred_on'],
            'finance_saving_funds' => ['id', 'user_id', 'name', 'fund_type', 'storage_mode',
                'account_id', 'linked_account_key', 'funding_account_id', 'category_id', 'currency_code',
                'target_mode', 'target_amount', 'deadline', 'top_up_mode', 'fixed_amount',
                'income_percent', 'expense_months', 'build_months', 'starts_on', 'monthday',
                'reminder_time', 'note', 'is_active', 'is_archived', 'archived_at', 'spent_at'],
            'finance_fund_movements' => ['id', 'user_id', 'finance_saving_fund_id', 'action',
                'delta_amount', 'currency_code', 'occurred_on', 'idempotency_key', 'payload_hash',
                'transaction_group_id', 'reverses_movement_id', 'note'],
            'finance_fund_occurrence_details' => ['id', 'user_id', 'planned_occurrence_id',
                'finance_saving_fund_id', 'fund_name', 'fund_type', 'storage_mode', 'account_id',
                'funding_account_id', 'category_id', 'amount', 'currency_code', 'top_up_mode',
                'calculation_basis', 'complete', 'missing_currencies'],
            'finance_fund_occurrence_facts' => ['id', 'user_id', 'planned_occurrence_id', 'outcome',
                'finance_fund_movement_id', 'transaction_group_id', 'occurred_on'],
            'finance_goal_details' => ['id', 'user_id', 'goal_id', 'kind', 'finance_saving_fund_id',
                'finance_debt_id', 'currency_code'],
        ] as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertTrue(Schema::hasColumns($table, $columns), $table);
        }

        $this->assertTrue(Schema::hasColumns('planned_occurrences', [
            'finance_debt_payment_fact_id', 'finance_fund_occurrence_fact_id',
        ]));
        $this->assertTrue(Schema::hasColumns('finance_transaction_groups', ['source_type', 'source_id']));
        $this->assertTrue(Schema::hasColumns('items', ['estimated_amount', 'estimated_currency_code']));
    }

    public function test_owner_names_and_finance_goal_targets_have_database_unique_guards(): void
    {
        $owner = User::factory()->create();
        FinanceCounterparty::factory()->create(['user_id' => $owner->id, 'name' => 'Bank']);

        try {
            FinanceCounterparty::factory()->create(['user_id' => $owner->id, 'name' => 'Bank']);
            $this->fail('A duplicate owner counterparty name was accepted.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        $fund = FinanceSavingFund::factory()->create(['user_id' => $owner->id]);
        $otherFund = FinanceSavingFund::factory()->create(['user_id' => $owner->id]);
        $goal = Goal::factory()->create(['user_id' => $owner->id, 'type' => Goal::TYPE_FINANCE]);
        FinanceGoalDetail::factory()->create([
            'user_id' => $owner->id, 'goal_id' => $goal->id, 'kind' => 'save',
            'finance_saving_fund_id' => $fund->id, 'finance_debt_id' => null,
        ]);

        try {
            FinanceGoalDetail::factory()->create([
                'user_id' => $owner->id, 'goal_id' => $goal->id, 'kind' => 'save',
                'finance_saving_fund_id' => $otherFund->id, 'finance_debt_id' => null,
            ]);
            $this->fail('A second detail for one Finance goal was accepted.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_rollback_removes_only_020_and_preserves_019_rows_then_reapplies(): void
    {
        $owner = User::factory()->create();
        $budget = FinanceBudgetLimit::factory()->create(['user_id' => $owner->id]);
        $migration = require database_path('migrations/2026_08_14_050000_create_debts_funds_financial_goals.php');

        $migration->down();

        $this->assertFalse(Schema::hasTable('finance_counterparties'));
        $this->assertFalse(Schema::hasTable('finance_goal_details'));
        $this->assertFalse(Schema::hasColumn('planned_occurrences', 'finance_debt_payment_fact_id'));
        $this->assertFalse(Schema::hasColumn('planned_occurrences', 'finance_fund_occurrence_fact_id'));
        $this->assertFalse(Schema::hasColumn('finance_transaction_groups', 'source_type'));
        $this->assertFalse(Schema::hasColumn('items', 'estimated_amount'));
        $this->assertTrue(Schema::hasTable('finance_budget_limits'));
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertDatabaseHas('finance_budget_limits', ['id' => $budget->id]);

        $migration->up();

        $this->assertTrue(Schema::hasTable('finance_counterparties'));
        $this->assertTrue(Schema::hasTable('finance_goal_details'));
        $this->assertTrue(Schema::hasColumn('planned_occurrences', 'finance_debt_payment_fact_id'));
        $this->assertTrue(Schema::hasColumn('finance_transaction_groups', 'source_type'));
        $this->assertTrue(Schema::hasColumn('items', 'estimated_amount'));
    }

    public function test_every_020_index_name_is_mysql_safe(): void
    {
        foreach ([
            'finance_counterparties', 'finance_debts', 'finance_debt_occurrence_details',
            'finance_debt_payment_facts', 'finance_saving_funds', 'finance_fund_movements',
            'finance_fund_occurrence_details', 'finance_fund_occurrence_facts', 'finance_goal_details',
            'planned_occurrences', 'finance_transaction_groups', 'items',
        ] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), $index['name']);
            }
        }
    }
}
