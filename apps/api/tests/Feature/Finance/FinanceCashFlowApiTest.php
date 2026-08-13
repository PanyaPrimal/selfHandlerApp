<?php

namespace Tests\Feature\Finance;

use Tests\Support\FinanceTestCase;

class FinanceCashFlowApiTest extends FinanceTestCase
{
    public function test_cash_flow_requires_a_current_or_future_canonical_month(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $this->actingAs($owner)->getJson('/api/finance/cash-flow?month=2026-08')
            ->assertOk()->assertJsonPath('data.month', '2026-08')
            ->assertJsonPath('data.complete', true);
        $this->actingAs($owner)->getJson('/api/finance/cash-flow?month=2026-07')->assertUnprocessable();
        $this->actingAs($owner)->getJson('/api/finance/cash-flow?month=08-2026')->assertUnprocessable();
    }
}
