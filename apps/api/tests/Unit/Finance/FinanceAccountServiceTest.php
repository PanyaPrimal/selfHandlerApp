<?php

namespace Tests\Unit\Finance;

use App\Models\FinanceAccount;
use App\Services\Finance\FinanceBalanceService;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinanceTestCase;

class FinanceAccountServiceTest extends FinanceTestCase
{
    public function test_balances_are_exact_grouped_and_zero_filled_in_one_query(): void
    {
        $owner = $this->owner();
        $uah = $this->account($owner);
        $usd = $this->account($owner, 'USD');
        $empty = $this->account($owner, 'EUR');
        $this->entry($owner, $uah, '0.1000');
        $this->entry($owner, $uah, '0.2000');
        $this->entry($owner, $usd, '-7.1234');

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $balances = app(FinanceBalanceService::class)->forAccounts(
            FinanceAccount::query()->whereIn('id', [$uah->id, $usd->id, $empty->id])->get(),
        );

        $this->assertSame('0.3000', $balances[$uah->id]);
        $this->assertSame('-7.1234', $balances[$usd->id]);
        $this->assertSame('0.0000', $balances[$empty->id]);
        $this->assertSame(2, $queries, 'Collection load plus one grouped balance query only.');
    }
}
