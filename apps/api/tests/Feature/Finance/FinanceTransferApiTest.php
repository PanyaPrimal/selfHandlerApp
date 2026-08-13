<?php

namespace Tests\Feature\Finance;

use Tests\Support\FinanceTestCase;

class FinanceTransferApiTest extends FinanceTestCase
{
    public function test_transfer_retry_foreign_archive_and_reversal_api_contract(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $other = $this->owner();
        $source = $this->account($owner);
        $destination = $this->account($owner, 'USD');
        $foreign = $this->account($other);
        $payload = [
            'idempotency_key' => 'transfer-api-1', 'source_account_id' => $source->id,
            'destination_account_id' => $destination->id, 'source_amount' => '41.0000',
            'destination_amount' => '1.0000', 'occurred_on' => '2026-08-13',
            'note' => 'Exchange', 'tag' => null,
        ];

        $first = $this->actingAs($owner)->postJson('/api/finance/transfers', $payload)
            ->assertCreated()->assertJsonCount(2, 'data.entries');
        $this->actingAs($owner)->postJson('/api/finance/transfers', $payload)
            ->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->actingAs($owner)->postJson('/api/finance/transfers', [
            ...$payload, 'destination_account_id' => $foreign->id,
        ])->assertNotFound();

        $this->actingAs($owner)->postJson('/api/finance/transactions/'.$first->json('data.id').'/reverse', [
            'idempotency_key' => 'reverse-transfer-api', 'reason' => 'Wrong account',
        ])->assertCreated()->assertJsonCount(2, 'data.entries')
            ->assertJsonPath('data.reverses_id', $first->json('data.id'));
        $this->actingAs($owner)->postJson('/api/finance/transactions/'.$first->json('data.id').'/reverse', [
            'idempotency_key' => 'reverse-transfer-again', 'reason' => 'Again',
        ])->assertConflict();
    }
}
