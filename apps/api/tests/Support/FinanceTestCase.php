<?php

namespace Tests\Support;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceLedgerEntry;
use App\Models\FinanceTransactionGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class FinanceTestCase extends TestCase
{
    use RefreshDatabase;

    protected function owner(string $timezone = 'Europe/Kyiv', string $baseCurrency = 'UAH'): User
    {
        $user = User::factory()->create();
        $user->ensureProfile()->update(['timezone' => $timezone, 'base_currency' => $baseCurrency]);

        return $user->fresh();
    }

    protected function account(User $owner, string $currency = 'UAH', array $attributes = []): FinanceAccount
    {
        return FinanceAccount::factory()->create($attributes + [
            'user_id' => $owner->id,
            'currency_code' => $currency,
        ]);
    }

    protected function category(User $owner, string $direction = 'expense'): FinanceCategory
    {
        return FinanceCategory::factory()->create(['user_id' => $owner->id, 'direction' => $direction]);
    }

    protected function entry(
        User $owner,
        FinanceAccount $account,
        string $delta,
        string $kind = 'adjustment',
        ?FinanceCategory $category = null,
        string $date = '2026-08-13',
    ): FinanceLedgerEntry {
        $group = FinanceTransactionGroup::factory()->create([
            'user_id' => $owner->id,
            'kind' => $kind,
            'occurred_on' => $date,
            'idempotency_key' => (string) Str::uuid(),
        ]);

        return FinanceLedgerEntry::factory()->create([
            'user_id' => $owner->id,
            'transaction_group_id' => $group->id,
            'account_id' => $account->id,
            'category_id' => $category?->id,
            'delta_amount' => $delta,
            'currency_code' => $account->currency_code,
        ]);
    }

    protected function freezeFinanceClock(): void
    {
        CarbonImmutable::setTestNow('2026-08-13 12:00:00 UTC');
    }
}
