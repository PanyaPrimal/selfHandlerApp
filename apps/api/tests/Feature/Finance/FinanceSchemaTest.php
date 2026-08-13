<?php

namespace Tests\Feature\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceExchangeRate;
use App\Models\FinanceLedgerEntry;
use App\Models\FinanceTransactionGroup;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinanceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_currency_and_finance_tables_have_the_closed_shape(): void
    {
        foreach ([
            'currencies' => ['code', 'decimal_places', 'is_active', 'created_at', 'updated_at'],
            'finance_accounts' => ['id', 'user_id', 'name', 'type', 'currency_code', 'archived_at'],
            'finance_categories' => ['id', 'user_id', 'direction', 'parent_id', 'parent_scope',
                'builtin_key', 'name', 'name_normalized', 'archived_at'],
            'finance_exchange_rates' => ['id', 'user_id', 'from_currency', 'to_currency',
                'rate_date', 'rate', 'source'],
            'finance_transaction_groups' => ['id', 'user_id', 'public_id', 'kind', 'occurred_on',
                'idempotency_key', 'payload_hash', 'note', 'tag', 'reverses_group_id',
                'reversal_reason', 'fx_from_currency', 'fx_to_currency', 'effective_rate'],
            'finance_ledger_entries' => ['id', 'user_id', 'transaction_group_id', 'account_id',
                'category_id', 'role', 'delta_amount', 'currency_code'],
        ] as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertTrue(Schema::hasColumns($table, $columns), $table);
        }

        $this->assertSame(['EUR', 'UAH', 'USD'], DB::table('currencies')->orderBy('code')->pluck('code')->all());
        $this->assertFalse(Schema::hasColumn('finance_accounts', 'balance'));
        $this->assertFalse(Schema::hasColumn('finance_accounts', 'opening_balance'));
    }

    public function test_every_new_identifier_is_mysql_safe(): void
    {
        foreach (['currencies', 'finance_accounts', 'finance_categories', 'finance_exchange_rates',
            'finance_transaction_groups', 'finance_ledger_entries'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), $index['name']);
            }
        }
    }

    public function test_every_private_entity_has_an_owner_safe_factory(): void
    {
        foreach ([
            FinanceAccount::factory()->create(),
            FinanceCategory::factory()->create(),
            FinanceExchangeRate::factory()->create(),
            FinanceTransactionGroup::factory()->create(),
            FinanceLedgerEntry::factory()->create(),
        ] as $model) {
            $this->assertNotNull($model->getKey(), $model::class);
            $this->assertNotNull($model->user_id, $model::class);
        }
    }

    public function test_domain_references_restrict_hard_delete_while_user_delete_cascades_private_graph(): void
    {
        $entry = FinanceLedgerEntry::factory()->create();
        $owner = $entry->user;
        $account = $entry->account;
        $group = $entry->transactionGroup;

        foreach ([$account, $group] as $referenced) {
            try {
                $referenced->getConnection()->table($referenced->getTable())
                    ->where('id', $referenced->id)->delete();
                $this->fail($referenced::class.' hard delete must be restricted by ledger history.');
            } catch (QueryException) {
                $this->assertDatabaseHas($referenced->getTable(), ['id' => $referenced->id]);
            }
        }

        $owner->delete();
        foreach (['finance_ledger_entries', 'finance_transaction_groups', 'finance_exchange_rates',
            'finance_categories', 'finance_accounts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('currencies', 3);
    }
}
