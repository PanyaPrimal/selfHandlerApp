<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_recurring_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('direction', 12);
            $table->foreignId('account_id')->constrained('finance_accounts')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('finance_categories')->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->char('currency_code', 3);
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('currency_code', 'fin_rec_ops_currency_fk')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->index(
                ['user_id', 'is_archived', 'is_active', 'name', 'id'],
                'fin_rec_ops_owner_lifecycle_name_idx',
            );
            $table->index(['user_id', 'account_id'], 'fin_rec_ops_owner_account_idx');
            $table->index(['user_id', 'category_id'], 'fin_rec_ops_owner_category_idx');
        });

        Schema::create('finance_budget_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('finance_categories')->restrictOnDelete();
            $table->date('budget_month');
            $table->decimal('limit_amount', 19, 4);
            $table->char('currency_code', 3);
            $table->timestamps();

            $table->foreign('currency_code', 'fin_budget_currency_fk')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(
                ['user_id', 'category_id', 'budget_month'],
                'fin_budget_owner_category_month_uq',
            );
            $table->index(['user_id', 'budget_month', 'id'], 'fin_budget_owner_month_idx');
        });

        Schema::create('recurring_rule_monthdays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recurring_rule_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('monthday');
            $table->timestamps();

            $table->unique(
                ['recurring_rule_id', 'monthday'],
                'rule_monthdays_rule_day_uq',
            );
            $table->index(['user_id', 'monthday'], 'rule_monthdays_owner_day_idx');
        });

        Schema::create('finance_occurrence_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('planned_occurrence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finance_recurring_operation_id')
                ->constrained('finance_recurring_operations')->restrictOnDelete();
            $table->string('operation_name', 160);
            $table->string('direction', 12);
            $table->foreignId('account_id')->constrained('finance_accounts')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('finance_categories')->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->char('currency_code', 3);
            $table->boolean('is_mandatory')->default(false);
            $table->timestamps();

            $table->foreign('currency_code', 'fin_occ_details_currency_fk')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->unique('planned_occurrence_id', 'fin_occ_details_occurrence_uq');
            $table->index(
                ['user_id', 'finance_recurring_operation_id'],
                'fin_occ_details_owner_operation_idx',
            );
            $table->index(['user_id', 'direction'], 'fin_occ_details_owner_direction_idx');
        });

        Schema::create('finance_occurrence_facts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('planned_occurrence_id')->constrained()->cascadeOnDelete();
            $table->string('outcome', 16);
            $table->foreignId('transaction_group_id')->nullable()
                ->constrained('finance_transaction_groups')->restrictOnDelete();
            $table->date('occurred_on')->nullable();
            $table->timestamps();

            $table->unique('planned_occurrence_id', 'fin_occ_facts_occurrence_uq');
            $table->unique('transaction_group_id', 'fin_occ_facts_transaction_uq');
            $table->index(['user_id', 'outcome', 'occurred_on'], 'fin_occ_facts_owner_outcome_date_idx');
        });

        Schema::table('planned_occurrences', function (Blueprint $table): void {
            $table->foreignId('finance_occurrence_fact_id')
                ->nullable()
                ->after('supplement_intake_id')
                ->constrained('finance_occurrence_facts')
                ->nullOnDelete();
            $table->unique('finance_occurrence_fact_id', 'planned_occ_finance_fact_uq');
        });
    }

    public function down(): void
    {
        Schema::table('planned_occurrences', function (Blueprint $table): void {
            $table->dropUnique('planned_occ_finance_fact_uq');
            $table->dropConstrainedForeignId('finance_occurrence_fact_id');
        });

        Schema::dropIfExists('finance_occurrence_facts');
        Schema::dropIfExists('finance_occurrence_details');
        Schema::dropIfExists('recurring_rule_monthdays');
        Schema::dropIfExists('finance_budget_limits');
        Schema::dropIfExists('finance_recurring_operations');
    }
};
