<?php

namespace Tests\Feature\Finance;

use Tests\Support\FinanceTestCase;

class FinanceTransactionApiTest extends FinanceTestCase
{
    public function test_actual_create_retry_conflict_and_bounded_list_contract(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $payload = [
            'idempotency_key' => 'expense-api-1', 'kind' => 'expense', 'account_id' => $account->id,
            'category_id' => $category->id, 'amount' => '12.3456', 'occurred_on' => '2026-08-12',
            'note' => null, 'tag' => 'food',
        ];

        $first = $this->actingAs($owner)->postJson('/api/finance/transactions', $payload)
            ->assertCreated()->assertJsonPath('data.entries.0.delta_amount', '-12.3456');
        $this->actingAs($owner)->postJson('/api/finance/transactions', $payload)
            ->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->actingAs($owner)->postJson('/api/finance/transactions', [...$payload, 'amount' => '1.0000'])
            ->assertConflict();
        $this->actingAs($owner)->postJson('/api/finance/transactions', [...$payload, 'unexpected' => 1])
            ->assertUnprocessable()->assertJsonValidationErrorFor('request');

        $this->actingAs($owner)->getJson('/api/finance/transactions?from=2026-08-01&to=2026-08-13')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->json('data.id'));
        $this->actingAs($owner)->getJson('/api/finance/transactions?from=2025-01-01&to=2026-08-13')
            ->assertUnprocessable();
    }
}
