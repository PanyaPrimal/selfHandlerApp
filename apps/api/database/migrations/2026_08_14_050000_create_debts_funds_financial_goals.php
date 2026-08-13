<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_counterparties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('kind', 16);
            $table->text('note')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'name'], 'fin_counterparty_owner_name_uq');
            $table->index(['user_id', 'is_archived', 'name'], 'fin_counterparty_owner_archive_idx');
        });

        Schema::create('finance_debts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finance_counterparty_id')->constrained('finance_counterparties')->restrictOnDelete();
            $table->foreignId('purchase_item_id')->nullable()->constrained('items')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('direction', 16);
            $table->string('repayment_mode', 16);
            $table->decimal('original_amount', 19, 4);
            $table->char('currency_code', 3);
            $table->date('originated_on');
            $table->date('deadline')->nullable();
            $table->foreignId('account_id')->nullable()->constrained('finance_accounts')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('finance_categories')->restrictOnDelete();
            $table->decimal('installment_amount', 19, 4)->nullable();
            $table->unsignedSmallInteger('installment_count')->nullable();
            $table->unsignedTinyInteger('interval_months')->nullable();
            $table->unsignedTinyInteger('monthday')->nullable();
            $table->date('first_due_on')->nullable();
            $table->time('reminder_time')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->foreign('currency_code', 'fin_debts_currency_fk')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique('purchase_item_id', 'fin_debts_purchase_uq');
            $table->index(['user_id', 'is_archived', 'direction', 'id'], 'fin_debts_owner_lifecycle_idx');
            $table->index(['user_id', 'finance_counterparty_id'], 'fin_debts_owner_counterparty_idx');
        });

        Schema::create('finance_saving_funds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('fund_type', 16);
            $table->string('storage_mode', 20);
            $table->foreignId('account_id')->constrained('finance_accounts')->restrictOnDelete();
            $table->foreignId('linked_account_key')->nullable()->constrained('finance_accounts')->restrictOnDelete();
            $table->foreignId('funding_account_id')->nullable()->constrained('finance_accounts')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('finance_categories')->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->string('target_mode', 20);
            $table->decimal('target_amount', 19, 4)->nullable();
            $table->date('deadline')->nullable();
            $table->string('top_up_mode', 20)->default('none');
            $table->decimal('fixed_amount', 19, 4)->nullable();
            $table->decimal('income_percent', 7, 4)->nullable();
            $table->unsignedTinyInteger('expense_months')->nullable();
            $table->unsignedTinyInteger('build_months')->nullable();
            $table->date('starts_on')->nullable();
            $table->unsignedTinyInteger('monthday')->nullable();
            $table->time('reminder_time')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('spent_at')->nullable();
            $table->timestamps();
            $table->foreign('currency_code', 'fin_funds_currency_fk')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique('linked_account_key', 'fin_funds_linked_account_uq');
            $table->index(['user_id', 'is_archived', 'fund_type', 'id'], 'fin_funds_owner_lifecycle_idx');
            $table->index(['user_id', 'account_id'], 'fin_funds_owner_account_idx');
        });

        Schema::create('finance_debt_occurrence_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('planned_occurrence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finance_debt_id')->constrained('finance_debts')->restrictOnDelete();
            $table->string('debt_name', 160);
            $table->string('direction', 16);
            $table->foreignId('account_id')->constrained('finance_accounts')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('finance_categories')->restrictOnDelete();
            $table->decimal('amount', 19, 4);
            $table->char('currency_code', 3);
            $table->timestamps();
            $table->foreign('currency_code', 'fin_debt_occ_currency_fk')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique('planned_occurrence_id', 'fin_debt_occurrence_uq');
            $table->index(['user_id', 'finance_debt_id'], 'fin_debt_occ_owner_debt_idx');
        });

        Schema::create('finance_debt_payment_facts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finance_debt_id')->constrained('finance_debts')->restrictOnDelete();
            $table->foreignId('planned_occurrence_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('transaction_group_id')->constrained('finance_transaction_groups')->restrictOnDelete();
            $table->decimal('principal_amount', 19, 4);
            $table->char('currency_code', 3);
            $table->date('occurred_on');
            $table->timestamps();
            $table->foreign('currency_code', 'fin_debt_pay_currency_fk')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique('transaction_group_id', 'fin_debt_pay_transaction_uq');
            $table->index(['user_id', 'finance_debt_id', 'occurred_on'], 'fin_debt_pay_owner_debt_date_idx');
            $table->index(['planned_occurrence_id', 'id'], 'fin_debt_pay_occurrence_idx');
        });

        Schema::create('finance_fund_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finance_saving_fund_id')->constrained('finance_saving_funds')->restrictOnDelete();
            $table->string('action', 16);
            $table->decimal('delta_amount', 19, 4);
            $table->char('currency_code', 3);
            $table->date('occurred_on');
            $table->string('idempotency_key', 120);
            $table->char('payload_hash', 64);
            $table->foreignId('transaction_group_id')->nullable()->constrained('finance_transaction_groups')->restrictOnDelete();
            $table->foreignId('reverses_movement_id')->nullable()->constrained('finance_fund_movements')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->foreign('currency_code', 'fin_fund_move_currency_fk')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['user_id', 'idempotency_key'], 'fin_fund_move_owner_idem_uq');
            $table->unique('transaction_group_id', 'fin_fund_move_transaction_uq');
            $table->unique('reverses_movement_id', 'fin_fund_move_reversal_uq');
            $table->index(['user_id', 'finance_saving_fund_id', 'occurred_on'], 'fin_fund_move_owner_fund_date_idx');
        });

        Schema::create('finance_fund_occurrence_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('planned_occurrence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finance_saving_fund_id')->constrained('finance_saving_funds')->restrictOnDelete();
            $table->string('fund_name', 160);
            $table->string('fund_type', 16);
            $table->string('storage_mode', 20);
            $table->foreignId('account_id')->constrained('finance_accounts')->restrictOnDelete();
            $table->foreignId('funding_account_id')->nullable()->constrained('finance_accounts')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('finance_categories')->restrictOnDelete();
            $table->decimal('amount', 19, 4)->nullable();
            $table->char('currency_code', 3);
            $table->string('top_up_mode', 20);
            $table->string('calculation_basis', 255)->nullable();
            $table->boolean('complete')->default(true);
            $table->json('missing_currencies')->nullable();
            $table->timestamps();
            $table->foreign('currency_code', 'fin_fund_occ_currency_fk')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique('planned_occurrence_id', 'fin_fund_occurrence_uq');
            $table->index(['user_id', 'finance_saving_fund_id'], 'fin_fund_occ_owner_fund_idx');
        });

        Schema::create('finance_fund_occurrence_facts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('planned_occurrence_id')->constrained()->cascadeOnDelete();
            $table->string('outcome', 16);
            $table->foreignId('finance_fund_movement_id')->nullable()->constrained('finance_fund_movements')->restrictOnDelete();
            $table->foreignId('transaction_group_id')->nullable()->constrained('finance_transaction_groups')->restrictOnDelete();
            $table->date('occurred_on')->nullable();
            $table->timestamps();
            $table->unique('planned_occurrence_id', 'fin_fund_fact_occurrence_uq');
            $table->unique('finance_fund_movement_id', 'fin_fund_fact_movement_uq');
            $table->unique('transaction_group_id', 'fin_fund_fact_transaction_uq');
            $table->index(['user_id', 'outcome', 'occurred_on'], 'fin_fund_fact_owner_outcome_idx');
        });

        Schema::create('finance_goal_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->foreignId('finance_saving_fund_id')->nullable()->constrained('finance_saving_funds')->restrictOnDelete();
            $table->foreignId('finance_debt_id')->nullable()->constrained('finance_debts')->restrictOnDelete();
            $table->char('currency_code', 3);
            $table->timestamps();
            $table->foreign('currency_code', 'fin_goal_currency_fk')->references('code')->on('currencies')->restrictOnDelete();
            $table->unique('goal_id', 'fin_goal_details_goal_uq');
            $table->index(['user_id', 'finance_saving_fund_id'], 'fin_goal_owner_fund_idx');
            $table->index(['user_id', 'finance_debt_id'], 'fin_goal_owner_debt_idx');
        });

        Schema::table('planned_occurrences', function (Blueprint $table): void {
            $table->foreignId('finance_debt_payment_fact_id')->nullable()->after('finance_occurrence_fact_id')
                ->constrained('finance_debt_payment_facts')->nullOnDelete();
            $table->foreignId('finance_fund_occurrence_fact_id')->nullable()->after('finance_debt_payment_fact_id')
                ->constrained('finance_fund_occurrence_facts')->nullOnDelete();
            $table->unique('finance_debt_payment_fact_id', 'planned_occ_debt_fact_uq');
            $table->unique('finance_fund_occurrence_fact_id', 'planned_occ_fund_fact_uq');
        });

        Schema::table('finance_transaction_groups', function (Blueprint $table): void {
            $table->string('source_type', 40)->nullable()->after('tag');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->index(['user_id', 'source_type', 'source_id'], 'fin_groups_owner_source_idx');
        });

        Schema::table('items', function (Blueprint $table): void {
            $table->decimal('estimated_amount', 19, 4)->nullable()->after('priority');
            $table->char('estimated_currency_code', 3)->nullable()->after('estimated_amount');
            $table->foreign('estimated_currency_code', 'items_estimated_currency_fk')
                ->references('code')->on('currencies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        $isSqlite = Schema::getConnection()->getDriverName() === 'sqlite';
        Schema::table('items', function (Blueprint $table) use ($isSqlite): void {
            $table->dropForeign($isSqlite ? ['estimated_currency_code'] : 'items_estimated_currency_fk');
            $table->dropColumn(['estimated_amount', 'estimated_currency_code']);
        });
        Schema::table('finance_transaction_groups', function (Blueprint $table): void {
            $table->dropIndex('fin_groups_owner_source_idx');
            $table->dropColumn(['source_type', 'source_id']);
        });
        Schema::table('planned_occurrences', function (Blueprint $table): void {
            $table->dropUnique('planned_occ_fund_fact_uq');
            $table->dropUnique('planned_occ_debt_fact_uq');
            $table->dropConstrainedForeignId('finance_fund_occurrence_fact_id');
            $table->dropConstrainedForeignId('finance_debt_payment_fact_id');
        });
        Schema::dropIfExists('finance_goal_details');
        Schema::dropIfExists('finance_fund_occurrence_facts');
        Schema::dropIfExists('finance_fund_occurrence_details');
        Schema::dropIfExists('finance_fund_movements');
        Schema::dropIfExists('finance_debt_payment_facts');
        Schema::dropIfExists('finance_debt_occurrence_details');
        Schema::dropIfExists('finance_saving_funds');
        Schema::dropIfExists('finance_debts');
        Schema::dropIfExists('finance_counterparties');
    }
};
