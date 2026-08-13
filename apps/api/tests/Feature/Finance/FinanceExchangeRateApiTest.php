<?php

namespace Tests\Feature\Finance;

use Tests\Support\FinanceTestCase;

class FinanceExchangeRateApiTest extends FinanceTestCase
{
    public function test_rate_upsert_list_pair_date_validation_and_ownership(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $other = $this->owner();
        $payload = [
            'from_currency' => 'USD', 'to_currency' => 'UAH',
            'rate_date' => '2026-08-12', 'rate' => '41.250000000000',
        ];

        $this->actingAs($owner)->putJson('/api/finance/exchange-rates', $payload)
            ->assertCreated()->assertJsonPath('data.rate', '41.250000000000');
        $this->actingAs($owner)->putJson('/api/finance/exchange-rates', [...$payload, 'rate' => '41.500000000000'])
            ->assertOk()->assertJsonPath('data.rate', '41.500000000000');
        $this->actingAs($other)->getJson('/api/finance/exchange-rates')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($owner)->getJson('/api/finance/exchange-rates?from_currency=USD&to_currency=UAH')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($owner)->putJson('/api/finance/exchange-rates', [
            ...$payload, 'to_currency' => 'USD',
        ])->assertUnprocessable();
        $this->actingAs($owner)->putJson('/api/finance/exchange-rates', [
            ...$payload, 'rate_date' => '2026-08-14',
        ])->assertUnprocessable();
        $this->actingAs($owner)->putJson('/api/finance/exchange-rates', [
            ...$payload, 'rate' => '0',
        ])->assertUnprocessable();
    }
}
