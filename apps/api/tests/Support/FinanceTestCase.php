<?php

namespace Tests\Support;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceCounterparty;
use App\Models\FinanceDebt;
use App\Models\FinanceLedgerEntry;
use App\Models\FinanceRecurringOperation;
use App\Models\FinanceSavingFund;
use App\Models\FinanceTransactionGroup;
use App\Models\User;
use App\Services\Finance\FinanceDebtService;
use App\Services\Finance\FinanceFundService;
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
    protected function counterparty(User $owner, array $attributes = []): FinanceCounterparty
    {
        return FinanceCounterparty::factory()->create($attributes + ['user_id' => $owner->id]);
    }

    /** @param array<string,mixed> $attributes */
    protected function flexibleDebt(
        User $owner,
        FinanceCounterparty $counterparty,
        FinanceAccount $account,
        FinanceCategory $category,
        array $attributes = [],
    ): FinanceDebt {
        return app(FinanceDebtService::class)->create($owner, array_replace([
            'name' => 'Flexible loan',
            'counterparty_id' => $counterparty->id,
            'direction' => 'owe',
            'repayment_mode' => 'flexible',
            'original_amount' => '1000.0000',
            'currency' => $account->currency_code,
            'originated_on' => '2026-08-01',
            'deadline' => null,
            'account_id' => $account->id,
            'category_id' => $category->id,
            'purchase_item_id' => null,
            'schedule' => null,
            'note' => null,
        ], $attributes));
    }

    /** @param array<string,mixed> $attributes */
    protected function regularFund(
        User $owner,
        FinanceAccount $account,
        array $attributes = [],
    ): FinanceSavingFund {
        $defaults = [
            'name' => 'Reserve',
            'fund_type' => 'regular',
            'storage_mode' => 'virtual',
            'account_id' => $account->id,
            'funding_account_id' => null,
            'category_id' => null,
            'currency' => $account->currency_code,
            'target_mode' => 'explicit',
            'target_amount' => '1000.0000',
            'deadline' => null,
            'rule' => [
                'top_up_mode' => 'none',
                'fixed_amount' => null,
                'income_percent' => null,
                'expense_months' => null,
                'build_months' => null,
                'starts_on' => null,
                'monthday' => null,
                'reminder_time' => null,
            ],
            'note' => null,
        ];
        if (isset($attributes['rule'])) {
            $attributes['rule'] = array_replace($defaults['rule'], $attributes['rule']);
        }

        return app(FinanceFundService::class)->create($owner, array_replace($defaults, $attributes));
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
