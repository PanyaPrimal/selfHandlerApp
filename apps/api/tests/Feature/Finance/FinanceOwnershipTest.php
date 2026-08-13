<?php

namespace Tests\Feature\Finance;

use Tests\Support\FinanceTestCase;

class FinanceOwnershipTest extends FinanceTestCase
{
    public function test_accounts_are_private_and_foreign_references_are_indistinguishable_from_missing(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $account = $this->account($owner);

        $this->actingAs($other)->getJson('/api/finance/accounts')->assertOk()->assertExactJson(['data' => []]);
        $this->actingAs($other)->patchJson("/api/finance/accounts/{$account->id}", ['name' => 'Taken'])
            ->assertNotFound();
        $this->actingAs($other)->postJson("/api/finance/accounts/{$account->id}/reconcile", [
            'idempotency_key' => 'foreign-reconcile',
            'observed_balance' => '1.0000',
            'occurred_on' => now()->toDateString(),
            'reason' => 'No access',
        ])->assertNotFound();

        $this->assertDatabaseHas('finance_accounts', ['id' => $account->id, 'name' => $account->name]);
    }
}
