<?php

namespace Tests\Unit\Finance;

use App\Models\FinanceExchangeRate;
use App\Services\Finance\FinanceBudgetService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\FinanceTestCase;

class FinanceBudgetServiceTest extends FinanceTestCase
{
    public function test_root_budget_counts_direct_and_child_expenses_with_reversals_once(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner, 'UAH');
        $root = $this->category($owner, 'expense');
        $child = $this->childCategory($owner, $root);
        $service = app(FinanceBudgetService::class);
        $service->create($owner, [
            'month' => '2026-08', 'category_id' => $root->id,
            'limit_amount' => '1000.0000', 'currency' => 'UAH',
        ]);
        $this->entry($owner, $account, '-300.0000', 'expense', $root, '2026-08-05');
        $this->entry($owner, $account, '-500.0000', 'expense', $child, '2026-08-06');
        $this->entry($owner, $account, '100.0000', 'expense', $child, '2026-08-07');

        $projection = $service->forMonth($owner, '2026-08')->sole();

        $this->assertSame('700.0000', $projection['actual_amount']);
        $this->assertSame('300.0000', $projection['remaining_amount']);
        $this->assertSame('70.0000', $projection['utilization_percent']);
        $this->assertSame('within', $projection['state']);
    }

    public function test_threshold_boundaries_are_exact(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $service = app(FinanceBudgetService::class);
        $service->create($owner, [
            'month' => '2026-08', 'category_id' => $category->id,
            'limit_amount' => '1000.0000', 'currency' => 'UAH',
        ]);

        foreach ([['-799.9900', 'within'], ['-0.0100', 'approaching'], ['-200.0100', 'exceeded']] as [$delta, $state]) {
            $this->entry($owner, $account, $delta, 'expense', $category, '2026-08-13');
            $this->assertSame($state, $service->forMonth($owner, '2026-08')->sole()['state']);
        }
    }

    public function test_missing_historical_fx_nulls_the_complete_projection_then_direct_rate_completes_it(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner, 'USD');
        $category = $this->category($owner, 'expense');
        $service = app(FinanceBudgetService::class);
        $service->create($owner, [
            'month' => '2026-08', 'category_id' => $category->id,
            'limit_amount' => '1000.0000', 'currency' => 'UAH',
        ]);
        $this->entry($owner, $account, '-10.0000', 'expense', $category, '2026-08-10');

        $missing = $service->forMonth($owner, '2026-08')->sole();
        $this->assertFalse($missing['complete']);
        $this->assertNull($missing['actual_amount']);
        $this->assertSame(['USD'], $missing['missing_currencies']);

        FinanceExchangeRate::factory()->create([
            'user_id' => $owner->id, 'from_currency' => 'USD', 'to_currency' => 'UAH',
            'rate_date' => '2026-08-09', 'rate' => '40.000000000000',
        ]);
        $complete = $service->forMonth($owner, '2026-08')->sole();
        $this->assertTrue($complete['complete']);
        $this->assertSame('400.0000', $complete['actual_amount']);
        $this->assertSame('40.0000', $complete['utilization_percent']);
    }

    public function test_same_month_ancestor_and_child_budgets_cannot_overlap(): void
    {
        $owner = $this->owner();
        $root = $this->category($owner, 'expense');
        $child = $this->childCategory($owner, $root);
        $service = app(FinanceBudgetService::class);
        $service->create($owner, [
            'month' => '2026-08', 'category_id' => $root->id,
            'limit_amount' => '1000.0000', 'currency' => 'UAH',
        ]);

        $this->expectException(HttpResponseException::class);
        $service->create($owner, [
            'month' => '2026-08', 'category_id' => $child->id,
            'limit_amount' => '200.0000', 'currency' => 'UAH',
        ]);
    }

    public function test_archived_budget_category_keeps_history_but_cannot_be_selected_on_update(): void
    {
        $owner = $this->owner();
        $existing = $this->category($owner, 'expense');
        $replacement = $this->category($owner, 'expense');
        $service = app(FinanceBudgetService::class);
        $budget = $service->create($owner, [
            'month' => '2026-08', 'category_id' => $existing->id,
            'limit_amount' => '1000.0000', 'currency' => 'UAH',
        ]);
        $existing->update(['archived_at' => now()]);
        $replacement->update(['archived_at' => now()]);

        $updated = $service->update($budget, $owner, ['limit_amount' => '900.0000']);
        $this->assertSame('900.0000', $updated->limit_amount);

        $this->expectException(ValidationException::class);
        $service->update($updated, $owner, ['category_id' => $replacement->id]);
    }

    public function test_month_projection_has_a_constant_query_budget(): void
    {
        $owner = $this->owner();
        $service = app(FinanceBudgetService::class);
        $service->create($owner, [
            'month' => '2026-08', 'category_id' => $this->category($owner)->id,
            'limit_amount' => '1000.0000', 'currency' => 'UAH',
        ]);
        $owner->calendarTimezone();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $service->forMonth($owner, '2026-08');
        $singleBudgetQueries = count(DB::getQueryLog());

        foreach (range(1, 4) as $index) {
            $service->create($owner, [
                'month' => '2026-08', 'category_id' => $this->category($owner)->id,
                'limit_amount' => (string) (1000 + $index), 'currency' => 'UAH',
            ]);
        }
        DB::flushQueryLog();
        $service->forMonth($owner, '2026-08');
        $multipleBudgetQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(5, $multipleBudgetQueries);
        $this->assertSame($singleBudgetQueries, $multipleBudgetQueries);
    }
}
