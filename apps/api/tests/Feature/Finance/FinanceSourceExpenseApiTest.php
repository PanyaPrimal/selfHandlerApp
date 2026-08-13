<?php

namespace Tests\Feature\Finance;

use App\Models\Item;
use App\Models\SupplementRestockProposal;
use Tests\Support\FinanceTestCase;

class FinanceSourceExpenseApiTest extends FinanceTestCase
{
    public function test_purchase_expense_is_idempotent_and_reversal_restores_wanted_state(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $purchase = Item::query()->create(['user_id' => $owner->id, 'type' => Item::TYPE_PURCHASE,
            'title' => 'Desk lamp', 'status' => Item::STATUS_ACTIVE, 'estimated_amount' => '300.0000',
            'estimated_currency_code' => 'UAH']);
        $payload = ['source_type' => 'purchase_item', 'source_id' => $purchase->id, 'account_id' => $account->id,
            'category_id' => $category->id, 'amount' => '300.0000', 'occurred_on' => '2026-08-13',
            'idempotency_key' => 'purchase-expense-1', 'note' => null];
        $first = $this->actingAs($owner)->postJson('/api/finance/source-expenses', $payload)
            ->assertCreated()->assertJsonPath('source.type', 'purchase_item');
        $this->assertSame(Item::STATUS_DONE, $purchase->fresh()->status);
        $this->actingAs($owner)->postJson('/api/finance/source-expenses', $payload)->assertOk()
            ->assertJsonPath('transaction_public_id', $first->json('transaction_public_id'));
        $this->actingAs($owner)->postJson('/api/finance/source-expenses', [...$payload, 'amount' => '301.0000'])
            ->assertConflict();
        $this->actingAs($owner)->postJson('/api/finance/transactions/'.$first->json('transaction_public_id').'/reverse', [
            'idempotency_key' => 'purchase-reverse-1', 'reason' => 'Returned',
        ])->assertCreated();
        $this->assertSame(Item::STATUS_ACTIVE, $purchase->fresh()->status);
    }

    public function test_restock_expense_never_mutates_proposal_or_stock(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $proposal = SupplementRestockProposal::factory()->create(['user_id' => $owner->id]);
        $this->actingAs($owner)->postJson('/api/finance/source-expenses', [
            'source_type' => 'supplement_restock_proposal', 'source_id' => $proposal->id, 'account_id' => $account->id,
            'category_id' => $category->id, 'amount' => '100.0000', 'occurred_on' => '2026-08-13',
            'idempotency_key' => 'restock-expense-1', 'note' => null,
        ])->assertCreated()->assertJsonPath('source.type', 'supplement_restock_proposal');
        $this->assertSame(SupplementRestockProposal::STATUS_OPEN, $proposal->fresh()->status);
        $this->assertDatabaseCount('supplement_stock_movements', 0);
    }
}
