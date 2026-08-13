<?php

namespace Tests\Unit\Finance;

use App\Services\Finance\FinanceAccountService;
use App\Services\Finance\FinanceFundMovementService;
use App\Services\Finance\FinanceFundService;
use Tests\Support\FinanceTestCase;

class FinanceFundServiceTest extends FinanceTestCase
{
    public function test_virtual_fund_reserves_without_moving_account_money(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner, 'UAH', ['name' => 'Main']);
        app(FinanceAccountService::class)->reconcile($account, $owner, [
            'observed_balance' => '1000.0000', 'occurred_on' => '2026-08-01',
            'reason' => 'Opening', 'idempotency_key' => 'fund-opening',
        ]);
        $funds = app(FinanceFundService::class);
        $fund = $funds->create($owner, [
            'name' => 'Trip', 'fund_type' => 'regular', 'storage_mode' => 'virtual',
            'account_id' => $account->id, 'funding_account_id' => null, 'category_id' => null,
            'currency' => 'UAH', 'target_mode' => 'explicit', 'target_amount' => '2000.0000',
            'deadline' => null, 'rule' => ['top_up_mode' => 'none', 'fixed_amount' => null,
                'income_percent' => null, 'expense_months' => null, 'build_months' => null,
                'starts_on' => null, 'monthday' => null, 'reminder_time' => null], 'note' => null,
        ]);
        app(FinanceFundMovementService::class)->move($owner, $fund, [
            'action' => 'top_up', 'amount' => '300.0000', 'counterparty_account_id' => null,
            'occurred_on' => '2026-08-13', 'idempotency_key' => 'reserve-1', 'note' => null,
        ]);

        $projection = $funds->one($owner, $fund, '2026-08');
        $this->assertSame('300.0000', $projection['projection']['saved_amount']);
        $accountProjection = app(FinanceAccountService::class)->list($owner)->sole();
        $this->assertSame('1000.0000', $accountProjection->balance_projection);
        $this->assertSame('700.0000', $accountProjection->available_balance_projection);
    }
}
