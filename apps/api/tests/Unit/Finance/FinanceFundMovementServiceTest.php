<?php

namespace Tests\Unit\Finance;

use App\Services\Finance\FinanceAccountService;
use App\Services\Finance\FinanceFundMovementService;
use App\Services\Finance\FinanceFundService;
use Illuminate\Validation\ValidationException;
use Tests\Support\FinanceTestCase;

class FinanceFundMovementServiceTest extends FinanceTestCase
{
    public function test_virtual_reserve_enforces_capacity_non_negative_value_and_append_only_reversal(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        app(FinanceAccountService::class)->reconcile($account, $owner, [
            'observed_balance' => '100.0000', 'occurred_on' => '2026-08-01',
            'reason' => 'Opening', 'idempotency_key' => 'virtual-capacity-opening',
        ]);
        $fund = app(FinanceFundService::class)->create($owner, $this->payload($account->id));
        $movements = app(FinanceFundMovementService::class);
        [$topUp] = $movements->move($owner, $fund, [
            'action' => 'top_up', 'amount' => '80.0000', 'counterparty_account_id' => null,
            'occurred_on' => '2026-08-13', 'idempotency_key' => 'virtual-topup', 'note' => null,
        ]);

        foreach ([
            ['action' => 'top_up', 'amount' => '20.0001', 'idempotency_key' => 'virtual-over-capacity'],
            ['action' => 'draw_down', 'amount' => '80.0001', 'idempotency_key' => 'virtual-below-zero'],
        ] as $invalid) {
            try {
                $movements->move($owner, $fund, [...$invalid, 'counterparty_account_id' => null,
                    'occurred_on' => '2026-08-13', 'note' => null]);
                $this->fail('Invalid virtual movement was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('amount', $exception->errors());
            }
        }

        [$reversal, $created] = $movements->move($owner, $fund, [
            'action' => 'reverse', 'amount' => null, 'counterparty_account_id' => null,
            'reverses_movement_id' => $topUp->id, 'occurred_on' => null,
            'idempotency_key' => 'virtual-topup-reverse', 'note' => 'Correction',
        ]);
        $this->assertTrue($created);
        $this->assertSame('-80.0000', (string) $reversal->delta_amount);
        $this->assertSame('0.0000', app(FinanceFundService::class)->one($owner, $fund, '2026-08')['projection']['saved_amount']);
        $this->assertDatabaseCount('finance_fund_movements', 2);
    }

    /** @return array<string,mixed> */
    private function payload(int $accountId): array
    {
        return ['name' => 'Reserve', 'fund_type' => 'regular', 'storage_mode' => 'virtual',
            'account_id' => $accountId, 'funding_account_id' => null, 'category_id' => null,
            'currency' => 'UAH', 'target_mode' => 'explicit', 'target_amount' => '500.0000',
            'deadline' => null, 'rule' => ['top_up_mode' => 'none', 'fixed_amount' => null,
                'income_percent' => null, 'expense_months' => null, 'build_months' => null,
                'starts_on' => null, 'monthday' => null, 'reminder_time' => null], 'note' => null];
    }
}
