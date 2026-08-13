<?php

namespace Tests\Unit\Finance;

use App\Services\Finance\FinanceFundService;
use App\Services\Finance\FinanceRecurringOperationService;
use Tests\Support\FinanceTestCase;

class FinanceEmergencyFundProjectionTest extends FinanceTestCase
{
    public function test_three_complete_month_expense_average_builds_exact_target_and_top_up(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $this->entry($owner, $account, '-900.0000', 'expense', $category, '2026-05-10');
        $this->entry($owner, $account, '-300.0000', 'expense', $category, '2026-06-10');
        $this->entry($owner, $account, '-600.0000', 'expense', $category, '2026-07-10');
        $service = app(FinanceFundService::class);
        $fund = $service->create($owner, $this->payload($account->id, [
            'top_up_mode' => 'expense_months', 'fixed_amount' => null, 'income_percent' => null,
            'expense_months' => 3, 'build_months' => 6, 'starts_on' => '2026-08-01',
            'monthday' => 15, 'reminder_time' => null,
        ], 'expense_months', null));
        $projection = $service->one($owner, $fund, '2026-08')['projection'];
        $this->assertTrue($projection['complete']);
        $this->assertFalse($projection['missing_history']);
        $this->assertSame('1800.0000', $projection['target_amount']);
        $this->assertSame('300.0000', $projection['suggested_top_up']);
    }

    public function test_missing_complete_month_is_unavailable_never_zero(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $this->entry($owner, $account, '-100.0000', 'expense', $category, '2026-06-10');
        $this->entry($owner, $account, '-100.0000', 'expense', $category, '2026-07-10');
        $service = app(FinanceFundService::class);
        $fund = $service->create($owner, $this->payload($account->id, [
            'top_up_mode' => 'expense_months', 'fixed_amount' => null, 'income_percent' => null,
            'expense_months' => 2, 'build_months' => 4, 'starts_on' => '2026-08-01',
            'monthday' => 15, 'reminder_time' => null,
        ], 'expense_months', null));
        $projection = $service->one($owner, $fund, '2026-08')['projection'];
        $this->assertFalse($projection['complete']);
        $this->assertTrue($projection['missing_history']);
        $this->assertNull($projection['target_amount']);
        $this->assertNull($projection['suggested_top_up']);
        $this->assertSame('unavailable', $projection['state']);
    }

    public function test_income_percent_uses_planned_income_for_selected_month(): void
    {
        $owner = $this->owner();
        $fundAccount = $this->account($owner);
        $incomeAccount = $this->account($owner);
        $incomeCategory = $this->category($owner, 'income');
        app(FinanceRecurringOperationService::class)->create($owner, [
            'name' => 'Salary', 'direction' => 'income', 'account_id' => $incomeAccount->id,
            'category_id' => $incomeCategory->id, 'amount' => '1000.0000', 'mandatory' => false,
            'starts_on' => '2026-08-01', 'ends_on' => null, 'interval_months' => 1,
            'month_days' => [10], 'reminder_time' => null,
        ]);
        $service = app(FinanceFundService::class);
        $fund = $service->create($owner, $this->payload($fundAccount->id, [
            'top_up_mode' => 'income_percent', 'fixed_amount' => null, 'income_percent' => 10,
            'expense_months' => null, 'build_months' => null, 'starts_on' => '2026-08-01',
            'monthday' => 15, 'reminder_time' => null,
        ], 'explicit', '5000.0000'));
        $projection = $service->one($owner, $fund, '2026-08')['projection'];
        $this->assertTrue($projection['complete']);
        $this->assertSame('100.0000', $projection['suggested_top_up']);
    }

    /** @param array<string,mixed> $rule @return array<string,mixed> */
    private function payload(int $accountId, array $rule, string $targetMode, ?string $target): array
    {
        return ['name' => 'Emergency', 'fund_type' => 'emergency', 'storage_mode' => 'virtual',
            'account_id' => $accountId, 'funding_account_id' => null, 'category_id' => null,
            'currency' => 'UAH', 'target_mode' => $targetMode, 'target_amount' => $target,
            'deadline' => null, 'rule' => $rule, 'note' => null];
    }
}
