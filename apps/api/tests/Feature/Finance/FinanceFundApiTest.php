<?php

namespace Tests\Feature\Finance;

use App\Services\Finance\FinanceAccountService;
use Tests\Support\FinanceTestCase;

class FinanceFundApiTest extends FinanceTestCase
{
    public function test_virtual_fund_api_reserves_available_money_once_and_is_owner_scoped(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $account = $this->account($owner);
        app(FinanceAccountService::class)->reconcile($account, $owner, [
            'observed_balance' => '500.0000', 'occurred_on' => '2026-08-01', 'reason' => 'Opening', 'idempotency_key' => 'fund-api-open']);
        $fund = $this->actingAs($owner)->postJson('/api/finance/saving-funds', [
            'name' => 'Buffer', 'fund_type' => 'regular', 'storage_mode' => 'virtual', 'account_id' => $account->id,
            'funding_account_id' => null, 'category_id' => null, 'currency' => 'UAH', 'target_mode' => 'explicit',
            'target_amount' => '1000.0000', 'deadline' => null, 'rule' => ['top_up_mode' => 'none',
                'fixed_amount' => null, 'income_percent' => null, 'expense_months' => null, 'build_months' => null,
                'starts_on' => null, 'monthday' => null, 'reminder_time' => null], 'note' => null,
        ])->assertCreated()->json('data');
        $payload = ['action' => 'top_up', 'amount' => '125.0000', 'counterparty_account_id' => null,
            'occurred_on' => '2026-08-13', 'idempotency_key' => 'fund-api-1', 'note' => null];
        $this->actingAs($owner)->postJson("/api/finance/saving-funds/{$fund['id']}/movements", $payload)
            ->assertCreated()->assertJsonPath('fund.projection.saved_amount', '125.0000');
        $this->actingAs($owner)->postJson("/api/finance/saving-funds/{$fund['id']}/movements", $payload)
            ->assertOk()->assertJsonPath('fund.projection.saved_amount', '125.0000');
        $this->actingAs($owner)->getJson('/api/finance/accounts')->assertJsonPath('data.0.balance', '500.0000')
            ->assertJsonPath('data.0.available_balance', '375.0000');
        $this->actingAs($other)->patchJson("/api/finance/saving-funds/{$fund['id']}", ['name' => 'Leak'])->assertNotFound();
    }
}
