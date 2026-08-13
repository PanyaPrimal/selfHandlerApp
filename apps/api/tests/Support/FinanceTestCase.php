<?php

namespace Tests\Support;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceLedgerEntry;
use App\Models\FinanceRecurringOperation;
use App\Models\FinanceTransactionGroup;
use App\Models\User;
use App\Services\Finance\FinanceRecurringOperationService;
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

    protected function childCategory(User $owner, FinanceCategory $parent): FinanceCategory
    {
        return FinanceCategory::factory()->create([
            'user_id' => $owner->id,
            'direction' => $parent->direction,
            'parent_id' => $parent->id,
        ]);
    }

    /** @param array<string,mixed> $attributes */
    protected function recurringOperation(
        User $owner,
        array $attributes = [],
        string $direction = 'expense',
        string $currency = 'UAH',
    ): FinanceRecurringOperation {
        $account = $this->account($owner, $currency);
        $category = $this->category($owner, $direction);

        return app(FinanceRecurringOperationService::class)->create($owner, array_replace([
            'name' => 'Monthly plan',
            'direction' => $direction,
            'account_id' => $account->id,
            'category_id' => $category->id,
            'amount' => '100.0000',
            'mandatory' => $direction === 'expense',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'interval_months' => 1,
            'month_days' => [15],
            'reminder_time' => null,
        ], $attributes));
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
