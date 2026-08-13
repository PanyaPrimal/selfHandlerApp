<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table): void {
            $table->char('code', 3)->primary();
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('currencies')->insertOrIgnore(array_map(
            fn (string $code): array => [
                'code' => $code,
                'decimal_places' => 2,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['UAH', 'USD', 'EUR'],
        ));

        Schema::create('finance_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type', 24);
            $table->char('currency_code', 3);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('currency_code', 'fin_accts_currency_fk')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->index(['user_id', 'archived_at', 'name'], 'fin_accts_owner_archive_name_idx');
            $table->index(['user_id', 'currency_code'], 'fin_accts_owner_currency_idx');
        });

        Schema::create('finance_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 12);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedBigInteger('parent_scope')->default(0);
            $table->string('builtin_key', 64)->nullable();
            $table->string('name', 120)->nullable();
            $table->string('name_normalized', 120);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('parent_id', 'fin_cats_parent_fk')
                ->references('id')->on('finance_categories')->restrictOnDelete();
            $table->unique(['user_id', 'builtin_key'], 'fin_cats_owner_builtin_uq');
            $table->unique(
                ['user_id', 'direction', 'parent_scope', 'name_normalized'],
                'fin_cats_owner_dir_scope_name_uq',
            );
            $table->index(['user_id', 'direction', 'archived_at'], 'fin_cats_owner_dir_archive_idx');
        });

        Schema::create('finance_exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('from_currency', 3);
            $table->char('to_currency', 3);
            $table->date('rate_date');
            $table->decimal('rate', 24, 12);
            $table->string('source', 16)->default('manual');
            $table->timestamps();

            $table->foreign('from_currency', 'fin_rates_from_currency_fk')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('to_currency', 'fin_rates_to_currency_fk')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(
                ['user_id', 'from_currency', 'to_currency', 'rate_date'],
                'fin_rates_owner_pair_date_uq',
            );
            $table->index(['user_id', 'rate_date'], 'fin_rates_owner_date_idx');
        });

        Schema::create('finance_transaction_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('public_id');
            $table->string('kind', 16);
            $table->date('occurred_on');
            $table->string('idempotency_key', 120);
            $table->char('payload_hash', 64);
            $table->string('note', 1000)->nullable();
            $table->string('tag', 80)->nullable();
            $table->unsignedBigInteger('reverses_group_id')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->char('fx_from_currency', 3)->nullable();
            $table->char('fx_to_currency', 3)->nullable();
            $table->decimal('effective_rate', 24, 12)->nullable();
            $table->timestamps();

            $table->foreign('reverses_group_id', 'fin_groups_reverses_fk')
                ->references('id')->on('finance_transaction_groups')->restrictOnDelete();
            $table->foreign('fx_from_currency', 'fin_groups_fx_from_fk')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->foreign('fx_to_currency', 'fin_groups_fx_to_fk')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['user_id', 'public_id'], 'fin_groups_owner_public_uq');
            $table->unique(['user_id', 'idempotency_key'], 'fin_groups_owner_idem_uq');
            $table->unique('reverses_group_id', 'fin_groups_reverses_uq');
            $table->index(['user_id', 'occurred_on', 'id'], 'fin_groups_owner_date_idx');
        });

        Schema::create('finance_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('transaction_group_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('role', 16);
            $table->decimal('delta_amount', 19, 4);
            $table->char('currency_code', 3);
            $table->timestamps();

            $table->foreign('transaction_group_id', 'fin_entries_group_fk')
                ->references('id')->on('finance_transaction_groups')->restrictOnDelete();
            $table->foreign('account_id', 'fin_entries_account_fk')
                ->references('id')->on('finance_accounts')->restrictOnDelete();
            $table->foreign('category_id', 'fin_entries_category_fk')
                ->references('id')->on('finance_categories')->restrictOnDelete();
            $table->foreign('currency_code', 'fin_entries_currency_fk')
                ->references('code')->on('currencies')->restrictOnDelete();
            $table->unique(['transaction_group_id', 'role'], 'fin_entries_group_role_uq');
            $table->index(['user_id', 'account_id', 'id'], 'fin_entries_owner_account_idx');
            $table->index(['user_id', 'category_id', 'id'], 'fin_entries_owner_category_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_ledger_entries');
        Schema::dropIfExists('finance_transaction_groups');
        Schema::dropIfExists('finance_exchange_rates');
        Schema::dropIfExists('finance_categories');
        Schema::dropIfExists('finance_accounts');
        Schema::dropIfExists('currencies');
    }
};
