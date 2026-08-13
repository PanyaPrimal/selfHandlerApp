<?php

namespace Tests\Unit\Finance;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceLedgerEntry;
use App\Models\FinanceTransactionGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class FinanceModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_parent_requires_same_owner_direction_and_root_depth(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $expenseRoot = FinanceCategory::factory()->create(['user_id' => $owner->id, 'direction' => 'expense']);
        $expenseChild = FinanceCategory::factory()->create([
            'user_id' => $owner->id, 'direction' => 'expense', 'parent_id' => $expenseRoot->id,
        ]);

        foreach ([
            ['user_id' => $owner->id, 'direction' => 'income', 'parent_id' => $expenseRoot->id],
            ['user_id' => $other->id, 'direction' => 'expense', 'parent_id' => $expenseRoot->id],
            ['user_id' => $owner->id, 'direction' => 'expense', 'parent_id' => $expenseChild->id],
        ] as $invalid) {
            try {
                FinanceCategory::factory()->create($invalid);
                $this->fail('Invalid category parent must be rejected.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_group_and_entries_are_append_only_and_same_owner_currency(): void
    {
        $entry = FinanceLedgerEntry::factory()->create();

        foreach ([$entry, $entry->transactionGroup] as $fact) {
            try {
                $fact->update($fact instanceof FinanceLedgerEntry
                    ? ['delta_amount' => '999.0000']
                    : ['note' => 'rewrite']);
                $this->fail('Accepted finance facts must be append-only.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }

            try {
                $fact->delete();
                $this->fail('Accepted finance facts must not be deleted.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        }

        $foreign = User::factory()->create();
        $foreignAccount = FinanceAccount::factory()->create(['user_id' => $foreign->id]);

        $this->expectException(RuntimeException::class);
        FinanceLedgerEntry::factory()->create([
            'user_id' => $entry->user_id,
            'transaction_group_id' => FinanceTransactionGroup::factory()->create([
                'user_id' => $entry->user_id,
            ])->id,
            'account_id' => $foreignAccount->id,
            'currency_code' => $foreignAccount->currency_code,
        ]);
    }
}
