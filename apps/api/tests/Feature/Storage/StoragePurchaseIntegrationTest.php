<?php

namespace Tests\Feature\Storage;

use App\Models\Item;
use Tests\Support\FinanceTestCase;

class StoragePurchaseIntegrationTest extends FinanceTestCase
{
    public function test_purchase_capture_defaults_to_wanted_and_keeps_estimate_as_one_money_pair(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->postJson('/api/storage/items', [
            'title' => 'New desk', 'type' => Item::TYPE_PURCHASE,
            'estimated_amount' => '2500.5000', 'estimated_currency_code' => 'UAH',
        ])->assertCreated()
            ->assertJsonPath('data.status', Item::STATUS_ACTIVE)
            ->assertJsonPath('data.estimated_amount', '2500.5000')
            ->assertJsonPath('data.estimated_currency_code', 'UAH');

        $this->actingAs($owner)->postJson('/api/storage/items', [
            'title' => 'Ordinary task',
        ])->assertCreated()->assertJsonPath('data.status', Item::STATUS_INBOX);

        foreach ([
            ['title' => 'Amount only', 'type' => Item::TYPE_PURCHASE, 'estimated_amount' => '10.0000'],
            ['title' => 'Currency only', 'type' => Item::TYPE_PURCHASE, 'estimated_currency_code' => 'UAH'],
            ['title' => 'Task estimate', 'type' => Item::TYPE_TASK, 'estimated_amount' => '10.0000',
                'estimated_currency_code' => 'UAH'],
            ['title' => 'Zero estimate', 'type' => Item::TYPE_PURCHASE, 'estimated_amount' => '0.0000',
                'estimated_currency_code' => 'UAH'],
        ] as $payload) {
            $this->actingAs($owner)->postJson('/api/storage/items', $payload)
                ->assertUnprocessable()->assertJsonValidationErrorFor('estimated_amount');
        }
    }

    public function test_purchase_cannot_claim_bought_without_finance_fact(): void
    {
        $owner = $this->owner();
        $purchase = Item::query()->create([
            'user_id' => $owner->id, 'title' => 'Monitor', 'type' => Item::TYPE_PURCHASE,
            'status' => Item::STATUS_ACTIVE,
        ]);

        $this->actingAs($owner)->patchJson("/api/storage/items/{$purchase->id}", [
            'status' => Item::STATUS_DONE,
        ])->assertUnprocessable()->assertJsonValidationErrorFor('status');

        $this->assertSame(Item::STATUS_ACTIVE, $purchase->fresh()->status);
    }

    public function test_direct_expense_unblocks_parent_and_reversal_restores_purchase_blocker(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $parent = Item::query()->create([
            'user_id' => $owner->id, 'title' => 'Finish office', 'status' => Item::STATUS_ACTIVE,
        ]);
        $purchase = Item::query()->create([
            'user_id' => $owner->id, 'title' => 'Buy chair', 'type' => Item::TYPE_PURCHASE,
            'status' => Item::STATUS_ACTIVE, 'parent_id' => $parent->id, 'is_blocker' => true,
            'estimated_amount' => '3000.0000', 'estimated_currency_code' => 'UAH',
        ]);

        $this->actingAs($owner)->patchJson("/api/storage/items/{$parent->id}", [
            'status' => Item::STATUS_DONE,
        ])->assertUnprocessable();

        $expense = $this->actingAs($owner)->postJson('/api/finance/source-expenses', [
            'source_type' => 'purchase_item', 'source_id' => $purchase->id,
            'account_id' => $account->id, 'category_id' => $category->id, 'amount' => '3000.0000',
            'occurred_on' => '2026-08-13', 'idempotency_key' => 'purchase-blocker-expense', 'note' => null,
        ])->assertCreated();

        $this->actingAs($owner)->patchJson("/api/storage/items/{$parent->id}", [
            'status' => Item::STATUS_DONE,
        ])->assertOk();
        $this->actingAs($owner)->patchJson("/api/storage/items/{$parent->id}", [
            'status' => Item::STATUS_ACTIVE,
        ])->assertOk();

        $this->actingAs($owner)->postJson(
            '/api/finance/transactions/'.$expense->json('transaction_public_id').'/reverse',
            ['idempotency_key' => 'purchase-blocker-reverse', 'reason' => 'Returned'],
        )->assertCreated();

        $this->assertSame(Item::STATUS_ACTIVE, $purchase->fresh()->status);
        $this->actingAs($owner)->patchJson("/api/storage/items/{$parent->id}", [
            'status' => Item::STATUS_DONE,
        ])->assertUnprocessable()->assertJsonValidationErrorFor('status');
    }
}
