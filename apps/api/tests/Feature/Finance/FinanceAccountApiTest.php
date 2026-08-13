<?php

namespace Tests\Feature\Finance;

use App\Models\FinanceLedgerEntry;
use App\Models\FinanceTransactionGroup;
use Tests\Support\FinanceTestCase;

class FinanceAccountApiTest extends FinanceTestCase
{
    public function test_create_with_opening_adjustment_and_list_derive_exact_balance(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();

        $created = $this->actingAs($owner)->postJson('/api/finance/accounts', [
            'name' => 'Daily card',
            'type' => 'card',
            'currency' => 'UAH',
            'opening_balance' => '123.4567',
            'opening_date' => '2026-08-12',
            'opening_note' => 'Imported statement',
        ])->assertCreated()
            ->assertExactJson(['data' => [
                'id' => 1,
                'name' => 'Daily card',
                'type' => 'card',
                'currency' => 'UAH',
                'balance' => '123.4567',
                'archived' => false,
                'created_at' => $this->jsonTimestamp($owner, '2026-08-13T12:00:00.000000Z'),
                'updated_at' => $this->jsonTimestamp($owner, '2026-08-13T12:00:00.000000Z'),
            ]]);

        $this->assertSame(1, $created->json('data.id'));
        $this->assertDatabaseCount('finance_transaction_groups', 1);
        $this->assertDatabaseHas('finance_transaction_groups', ['kind' => 'adjustment', 'occurred_on' => '2026-08-12']);
        $this->assertDatabaseHas('finance_ledger_entries', ['delta_amount' => '123.4567', 'role' => 'primary']);

        $this->actingAs($owner)->getJson('/api/finance/accounts')
            ->assertOk()->assertJsonPath('data.0.balance', '123.4567');
    }

    public function test_account_lifecycle_currency_lock_and_strict_validation(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $account = $this->account($owner);
        $this->entry($owner, $account, '10.0000');

        $this->actingAs($owner)->patchJson("/api/finance/accounts/{$account->id}", ['currency' => 'USD'])
            ->assertUnprocessable();
        $this->actingAs($owner)->patchJson("/api/finance/accounts/{$account->id}", ['archived' => true])
            ->assertUnprocessable();
        $this->actingAs($owner)->patchJson("/api/finance/accounts/{$account->id}", ['name' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.name', 'Renamed');
        $this->actingAs($owner)->patchJson("/api/finance/accounts/{$account->id}", ['future' => true])
            ->assertUnprocessable()->assertJsonValidationErrorFor('request');

        $zero = $this->account($owner, 'USD');
        $this->actingAs($owner)->patchJson("/api/finance/accounts/{$zero->id}", ['archived' => true])
            ->assertOk()->assertJsonPath('data.archived', true);
        $this->actingAs($owner)->patchJson("/api/finance/accounts/{$zero->id}", ['archived' => false])
            ->assertOk()->assertJsonPath('data.archived', false);
    }

    public function test_reconciliation_appends_one_exact_idempotent_adjustment(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $account = $this->account($owner);
        $this->entry($owner, $account, '10.1250');

        $payload = [
            'idempotency_key' => 'reconcile-2026-08-13',
            'observed_balance' => '9.9999',
            'occurred_on' => '2026-08-13',
            'reason' => 'Statement check',
        ];
        $first = $this->actingAs($owner)->postJson("/api/finance/accounts/{$account->id}/reconcile", $payload)
            ->assertOk()->assertJsonPath('data.balance', '9.9999')
            ->assertJsonPath('transaction.kind', 'adjustment');
        $second = $this->actingAs($owner)->postJson("/api/finance/accounts/{$account->id}/reconcile", $payload)
            ->assertOk()->assertJsonPath('transaction.id', $first->json('transaction.id'));

        $this->assertSame(2, FinanceTransactionGroup::query()->count());
        $this->assertSame('-0.1251', FinanceLedgerEntry::query()->latest('id')->value('delta_amount'));

        $this->actingAs($owner)->postJson("/api/finance/accounts/{$account->id}/reconcile", [
            ...$payload, 'observed_balance' => '8.0000',
        ])->assertConflict();
    }

    private function jsonTimestamp(object $unused, string $value): string
    {
        return $value;
    }
}
