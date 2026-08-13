<?php

namespace Tests\Unit\Finance;

use App\Models\FinanceLedgerEntry;
use App\Services\Finance\FinanceLedgerService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Tests\Support\FinanceTestCase;

class FinanceReversalServiceTest extends FinanceTestCase
{
    public function test_one_linked_reversal_negates_exact_entries_and_preserves_original(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $service = app(FinanceLedgerService::class);
        [$original] = $service->postActual($owner, [
            'idempotency_key' => 'expense-to-reverse', 'kind' => 'expense', 'account_id' => $account->id,
            'category_id' => $category->id, 'amount' => '8.4321', 'occurred_on' => '2026-08-12',
            'note' => null, 'tag' => null,
        ]);

        [$reversal] = $service->reverse($owner, $original, [
            'idempotency_key' => 'reverse-expense-1', 'reason' => 'Duplicate',
        ]);

        $this->assertSame($original->id, $reversal->reverses_group_id);
        $this->assertSame('8.4321', $reversal->entries->sole()->delta_amount);
        $this->assertSame('-8.4321', $original->fresh()->entries->sole()->delta_amount);
        $this->assertSame('0.0000', FinanceLedgerEntry::query()->pluck('delta_amount')->reduce(
            fn (string $sum, mixed $value): string => bcadd($sum, (string) $value, 4), '0.0000',
        ));

        $this->expectException(HttpResponseException::class);
        $service->reverse($owner, $original->fresh(), [
            'idempotency_key' => 'reverse-expense-2', 'reason' => 'Again',
        ]);
    }
}
