<?php

namespace Tests\Feature\Finance;

use Tests\Support\FinanceTestCase;

class FinanceSummaryApiTest extends FinanceTestCase
{
    public function test_summary_uses_profile_base_currency_and_closed_bounded_inputs(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner(baseCurrency: 'EUR');
        $this->account($owner, 'EUR');

        $this->actingAs($owner)->getJson('/api/finance/summary?from=2026-08-01&to=2026-08-13&as_of=2026-08-13')
            ->assertOk()
            ->assertJsonPath('data.consolidated.base_currency', 'EUR')
            ->assertJsonPath('data.consolidated.total', '0.0000')
            ->assertJsonPath('data.actuals.base_currency', 'EUR');
        $this->actingAs($owner)->getJson('/api/finance/summary?from=2026-08-13&to=2026-08-01')
            ->assertUnprocessable();
        $this->actingAs($owner)->getJson('/api/finance/summary?unknown=1')
            ->assertUnprocessable();
    }

    public function test_currency_reference_endpoint_is_authenticated_and_closed(): void
    {
        $this->getJson('/api/finance/currencies')->assertUnauthorized();
        $owner = $this->owner();
        $this->actingAs($owner)->getJson('/api/finance/currencies')->assertOk()->assertExactJson([
            'data' => [
                ['code' => 'EUR', 'decimal_places' => 2, 'active' => true],
                ['code' => 'UAH', 'decimal_places' => 2, 'active' => true],
                ['code' => 'USD', 'decimal_places' => 2, 'active' => true],
            ],
        ]);
    }
}
