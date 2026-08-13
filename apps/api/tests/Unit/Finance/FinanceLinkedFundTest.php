<?php

namespace Tests\Unit\Finance;

use App\Services\Finance\FinanceAccountService;
use App\Services\Finance\FinanceFundMovementService;
use App\Services\Finance\FinanceFundService;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Tests\Support\FinanceTestCase;

class FinanceLinkedFundTest extends FinanceTestCase
{
    public function test_linked_fund_uses_same_currency_transfers_and_reversal_restores_balances(): void
    {
        $owner = $this->owner();
        $funding = $this->account($owner, 'UAH', ['name' => 'Main']);
        $linked = $this->account($owner, 'UAH', ['name' => 'Savings']);
        app(FinanceAccountService::class)->reconcile($funding, $owner, [
            'observed_balance' => '500.0000', 'occurred_on' => '2026-08-01',
            'reason' => 'Opening', 'idempotency_key' => 'linked-opening',
        ]);
        $fund = app(FinanceFundService::class)->create($owner, $this->payload($linked->id, $funding->id));
        [$movement] = app(FinanceFundMovementService::class)->move($owner, $fund, [
            'action' => 'top_up', 'amount' => '200.0000', 'counterparty_account_id' => null,
            'occurred_on' => '2026-08-13', 'idempotency_key' => 'linked-topup', 'note' => null,
        ]);

        $accounts = app(FinanceAccountService::class)->list($owner)->keyBy('id');
        $this->assertSame('300.0000', $accounts[$funding->id]->balance_projection);
        $this->assertSame('200.0000', $accounts[$linked->id]->balance_projection);
        $this->assertSame('200.0000', app(FinanceFundService::class)->one($owner, $fund, '2026-08')['projection']['saved_amount']);

        app(FinanceFundMovementService::class)->move($owner, $fund, [
            'action' => 'reverse', 'amount' => null, 'counterparty_account_id' => null,
            'reverses_movement_id' => $movement->id, 'occurred_on' => null,
            'idempotency_key' => 'linked-topup-reverse', 'note' => 'Correction',
        ]);
        $restored = app(FinanceAccountService::class)->list($owner)->keyBy('id');
        $this->assertSame('500.0000', $restored[$funding->id]->balance_projection);
        $this->assertSame('0.0000', $restored[$linked->id]->balance_projection);
    }

    public function test_one_linked_account_cannot_back_two_funds(): void
    {
        $owner = $this->owner();
        $funding = $this->account($owner);
        $linked = $this->account($owner);
        $service = app(FinanceFundService::class);
        $service->create($owner, $this->payload($linked->id, $funding->id));

        try {
            $service->create($owner, [...$this->payload($linked->id, $funding->id), 'name' => 'Duplicate']);
            $this->fail('A linked account was claimed by two funds.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('account_id', $exception->errors());
        } catch (QueryException) {
            $this->fail('The service leaked a database uniqueness exception.');
        }
    }

    public function test_active_fund_reference_blocks_account_archive_and_currency_rewrite(): void
    {
        $owner = $this->owner();
        $funding = $this->account($owner);
        $linked = $this->account($owner);
        app(FinanceFundService::class)->create($owner, $this->payload($linked->id, $funding->id));

        foreach ([['archived' => true], ['currency' => 'USD']] as $change) {
            try {
                app(FinanceAccountService::class)->update($linked, $owner, $change);
                $this->fail('An active fund account reference was orphaned.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }

    /** @return array<string,mixed> */
    private function payload(int $linkedId, int $fundingId): array
    {
        return ['name' => 'Linked reserve', 'fund_type' => 'regular', 'storage_mode' => 'linked_account',
            'account_id' => $linkedId, 'funding_account_id' => $fundingId, 'category_id' => null,
            'currency' => 'UAH', 'target_mode' => 'explicit', 'target_amount' => '1000.0000',
            'deadline' => null, 'rule' => ['top_up_mode' => 'none', 'fixed_amount' => null,
                'income_percent' => null, 'expense_months' => null, 'build_months' => null,
                'starts_on' => null, 'monthday' => null, 'reminder_time' => null], 'note' => null];
    }
}
