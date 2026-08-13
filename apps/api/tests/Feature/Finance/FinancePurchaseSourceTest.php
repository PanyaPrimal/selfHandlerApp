<?php

namespace Tests\Feature\Finance;

use App\Models\FinanceCounterparty;
use App\Models\Item;
use Tests\Support\FinanceTestCase;

class FinancePurchaseSourceTest extends FinanceTestCase
{
    public function test_direct_purchase_expense_is_single_path_retry_safe_reversible_and_correctable(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $purchase = Item::query()->create([
            'user_id' => $owner->id, 'title' => 'Coffee machine', 'type' => Item::TYPE_PURCHASE,
            'status' => Item::STATUS_ACTIVE,
        ]);
        $payload = [
            'source_type' => 'purchase_item', 'source_id' => $purchase->id,
            'account_id' => $account->id, 'category_id' => $category->id, 'amount' => '8000.0000',
            'occurred_on' => '2026-08-13', 'idempotency_key' => 'purchase-source-first', 'note' => null,
        ];

        $first = $this->actingAs($owner)->postJson('/api/finance/source-expenses', $payload)
            ->assertCreated()->assertJsonPath('source.active', false);
        $this->actingAs($owner)->postJson('/api/finance/source-expenses', $payload)
            ->assertOk()->assertJsonPath('transaction_public_id', $first->json('transaction_public_id'));
        $this->actingAs($owner)->postJson('/api/finance/source-expenses', [
            ...$payload, 'idempotency_key' => 'purchase-source-conflict',
        ])->assertUnprocessable();

        $this->actingAs($owner)->postJson(
            '/api/finance/transactions/'.$first->json('transaction_public_id').'/reverse',
            ['idempotency_key' => 'purchase-source-reverse', 'reason' => 'Wrong amount'],
        )->assertCreated();
        $corrected = $this->actingAs($owner)->postJson('/api/finance/source-expenses', [
            ...$payload, 'amount' => '7900.0000', 'idempotency_key' => 'purchase-source-corrected',
        ])->assertCreated();

        $this->assertNotSame($first->json('transaction_public_id'), $corrected->json('transaction_public_id'));
        $this->assertDatabaseCount('finance_transaction_groups', 3);
        $this->assertSame(Item::STATUS_DONE, $purchase->fresh()->status);
        $this->actingAs($owner)->getJson('/api/finance/transactions?from=2026-08-01&to=2026-08-13')
            ->assertOk()->assertJsonFragment([
                'type' => 'purchase_item', 'id' => $purchase->id, 'label' => 'Coffee machine',
                'action_url' => '/storage?item='.$purchase->id, 'active' => false,
            ]);
    }

    public function test_active_direct_expense_blocks_installment_debt(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $counterparty = FinanceCounterparty::factory()->create(['user_id' => $owner->id]);
        $purchase = Item::query()->create([
            'user_id' => $owner->id, 'title' => 'Bicycle', 'type' => Item::TYPE_PURCHASE,
            'status' => Item::STATUS_ACTIVE,
        ]);
        $this->actingAs($owner)->postJson('/api/finance/source-expenses', [
            'source_type' => 'purchase_item', 'source_id' => $purchase->id,
            'account_id' => $account->id, 'category_id' => $category->id, 'amount' => '12000.0000',
            'occurred_on' => '2026-08-13', 'idempotency_key' => 'purchase-before-debt', 'note' => null,
        ])->assertCreated();

        $this->actingAs($owner)->postJson('/api/finance/debts', [
            'name' => 'Bicycle installments', 'counterparty_id' => $counterparty->id, 'direction' => 'owe',
            'repayment_mode' => 'fixed', 'original_amount' => '12000.0000', 'currency' => 'UAH',
            'originated_on' => '2026-08-13', 'deadline' => null, 'account_id' => $account->id,
            'category_id' => $category->id, 'purchase_item_id' => $purchase->id, 'note' => null,
            'schedule' => ['installment_amount' => '4000.0000', 'installment_count' => 3,
                'interval_months' => 1, 'monthday' => 13, 'first_due_on' => '2026-08-13',
                'reminder_time' => null],
        ])->assertUnprocessable()->assertJsonValidationErrorFor('purchase_item_id');
    }

    public function test_foreign_non_purchase_and_canceled_sources_are_hidden_or_rejected(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $foreign = Item::query()->create([
            'user_id' => $other->id, 'title' => 'Foreign', 'type' => Item::TYPE_PURCHASE,
            'status' => Item::STATUS_ACTIVE,
        ]);
        $task = Item::query()->create(['user_id' => $owner->id, 'title' => 'Task']);
        $canceled = Item::query()->create([
            'user_id' => $owner->id, 'title' => 'Canceled', 'type' => Item::TYPE_PURCHASE,
            'status' => Item::STATUS_DROPPED,
        ]);

        foreach ([[$foreign, 'foreign'], [$task, 'task'], [$canceled, 'canceled']] as [$source, $suffix]) {
            $response = $this->actingAs($owner)->postJson('/api/finance/source-expenses', [
                'source_type' => 'purchase_item', 'source_id' => $source->id,
                'account_id' => $account->id, 'category_id' => $category->id, 'amount' => '10.0000',
                'occurred_on' => '2026-08-13', 'idempotency_key' => 'purchase-invalid-'.$suffix, 'note' => null,
            ]);
            $suffix === 'foreign' ? $response->assertNotFound() : $response->assertUnprocessable();
        }
    }
}
