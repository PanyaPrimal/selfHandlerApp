<?php

namespace Tests\Unit\Finance;

use App\Services\Finance\FinanceLedgerService;
use Illuminate\Validation\ValidationException;
use Tests\Support\FinanceTestCase;

class FinanceTransferServiceTest extends FinanceTestCase
{
    public function test_same_and_cross_currency_transfers_are_atomic_exact_pairs_with_rate_snapshot(): void
    {
        $owner = $this->owner();
        $uahA = $this->account($owner);
        $uahB = $this->account($owner);
        $usd = $this->account($owner, 'USD');
        $service = app(FinanceLedgerService::class);

        [$same] = $service->transfer($owner, [
            'idempotency_key' => 'same-currency-transfer', 'source_account_id' => $uahA->id,
            'destination_account_id' => $uahB->id, 'source_amount' => '1.2500',
            'destination_amount' => '1.25', 'occurred_on' => '2026-08-13', 'note' => null, 'tag' => null,
        ]);
        $this->assertSame(['-1.2500', '1.2500'], $same->entries->pluck('delta_amount')->all());
        $this->assertNull($same->effective_rate);

        [$cross] = $service->transfer($owner, [
            'idempotency_key' => 'cross-currency-transfer', 'source_account_id' => $uahA->id,
            'destination_account_id' => $usd->id, 'source_amount' => '410.0000',
            'destination_amount' => '10.0000', 'occurred_on' => '2026-08-13', 'note' => null, 'tag' => null,
        ]);
        $this->assertSame(['-410.0000', '10.0000'], $cross->entries->pluck('delta_amount')->all());
        $this->assertSame('0.024390243902', $cross->effective_rate);
        $this->assertSame('UAH', $cross->fx_from_currency);
        $this->assertSame('USD', $cross->fx_to_currency);
    }

    public function test_transfer_refuses_same_account_or_unequal_same_currency_amounts_atomically(): void
    {
        $owner = $this->owner();
        $source = $this->account($owner);
        $destination = $this->account($owner);
        $service = app(FinanceLedgerService::class);

        foreach ([
            ['destination_account_id' => $source->id, 'destination_amount' => '1.0000'],
            ['destination_account_id' => $destination->id, 'destination_amount' => '2.0000'],
        ] as $case) {
            try {
                $service->transfer($owner, [
                    'idempotency_key' => 'invalid-transfer-'.$case['destination_account_id'].'-'.$case['destination_amount'],
                    'source_account_id' => $source->id, 'source_amount' => '1.0000',
                    'occurred_on' => '2026-08-13', 'note' => null, 'tag' => null, ...$case,
                ]);
                $this->fail('Invalid transfer must fail.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('finance_transaction_groups', 0);
            }
        }
    }
}
