<?php

namespace Tests\Feature\Finance;

use App\Models\SupplementRestockProposal;
use Tests\Support\FinanceTestCase;

class FinanceRestockSourceTest extends FinanceTestCase
{
    public function test_restock_expense_is_retry_safe_reversible_and_never_changes_inventory_truth(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $proposal = SupplementRestockProposal::factory()->create(['user_id' => $owner->id]);
        $payload = [
            'source_type' => 'supplement_restock_proposal', 'source_id' => $proposal->id,
            'account_id' => $account->id, 'category_id' => $category->id, 'amount' => '450.0000',
            'occurred_on' => '2026-08-13', 'idempotency_key' => 'restock-source-first', 'note' => null,
        ];

        $first = $this->actingAs($owner)->postJson('/api/finance/source-expenses', $payload)
            ->assertCreated()->assertJsonPath('source.active', true);
        $this->actingAs($owner)->postJson('/api/finance/source-expenses', $payload)
            ->assertOk()->assertJsonPath('transaction_public_id', $first->json('transaction_public_id'));
        $this->actingAs($owner)->postJson('/api/finance/source-expenses', [
            ...$payload, 'idempotency_key' => 'restock-source-conflict',
        ])->assertConflict();

        $this->actingAs($owner)->postJson(
            '/api/finance/transactions/'.$first->json('transaction_public_id').'/reverse',
            ['idempotency_key' => 'restock-source-reverse', 'reason' => 'Wrong card'],
        )->assertCreated();
        $this->actingAs($owner)->postJson('/api/finance/source-expenses', [
            ...$payload, 'amount' => '455.0000', 'idempotency_key' => 'restock-source-corrected',
        ])->assertCreated();

        $this->assertSame(SupplementRestockProposal::STATUS_OPEN, $proposal->fresh()->status);
        $this->assertDatabaseCount('supplement_stock_movements', 0);
        $this->assertDatabaseCount('finance_transaction_groups', 3);
    }

    public function test_closed_and_foreign_restock_proposals_cannot_be_spent(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $closed = SupplementRestockProposal::factory()->create([
            'user_id' => $owner->id, 'status' => SupplementRestockProposal::STATUS_RESOLVED,
            'resolved_at' => now(), 'active_supplement_id' => null,
        ]);
        $foreign = SupplementRestockProposal::factory()->create(['user_id' => $other->id]);

        foreach ([[$closed, 'closed'], [$foreign, 'foreign']] as [$proposal, $suffix]) {
            $response = $this->actingAs($owner)->postJson('/api/finance/source-expenses', [
                'source_type' => 'supplement_restock_proposal', 'source_id' => $proposal->id,
                'account_id' => $account->id, 'category_id' => $category->id, 'amount' => '100.0000',
                'occurred_on' => '2026-08-13', 'idempotency_key' => 'restock-invalid-'.$suffix, 'note' => null,
            ]);
            $suffix === 'foreign' ? $response->assertNotFound() : $response->assertUnprocessable();
        }
    }
}
