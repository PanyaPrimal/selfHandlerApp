<?php

namespace Tests\Unit\Finance;

use App\Models\FinanceCounterparty;
use App\Models\FinanceDebt;
use App\Models\FinanceGoalDetail;
use App\Models\FinanceSavingFund;
use App\Models\Goal;
use App\Services\Finance\FinanceDebtService;
use App\Services\Finance\FinanceFundService;
use App\Services\Finance\FinanceGoalService;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinanceTestCase;

class FinanceCommitmentQueryBudgetTest extends FinanceTestCase
{
    public function test_debt_and_fund_list_queries_do_not_grow_with_aggregate_count(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $owner->ensureProfile();
        $counterparty = FinanceCounterparty::factory()->create(['user_id' => $owner->id]);
        FinanceDebt::factory()->create(['user_id' => $owner->id, 'finance_counterparty_id' => $counterparty->id]);
        FinanceSavingFund::factory()->create(['user_id' => $owner->id, 'account_id' => $account->id]);
        $debtOne = $this->queries(fn () => app(FinanceDebtService::class)->list($owner));
        $fundOne = $this->queries(fn () => app(FinanceFundService::class)->list($owner, '2026-08'));
        FinanceDebt::factory()->count(4)->create(['user_id' => $owner->id, 'finance_counterparty_id' => $counterparty->id]);
        FinanceSavingFund::factory()->count(4)->create(['user_id' => $owner->id, 'account_id' => $account->id]);
        $debtMany = $this->queries(fn () => app(FinanceDebtService::class)->list($owner));
        $fundMany = $this->queries(fn () => app(FinanceFundService::class)->list($owner, '2026-08'));
        $this->assertSame($debtOne, $debtMany, "debt {$debtOne}/{$debtMany}");
        $this->assertSame($fundOne, $fundMany, "fund {$fundOne}/{$fundMany}");
    }

    public function test_finance_goal_list_queries_do_not_grow_with_goal_count(): void
    {
        $owner = $this->owner();
        $owner->ensureProfile();
        $account = $this->account($owner);
        $this->makeSaveGoal($owner->id, $account->id);
        $one = $this->queries(fn () => app(FinanceGoalService::class)->list($owner));
        foreach (range(1, 4) as $_) {
            $this->makeSaveGoal($owner->id, $account->id);
        }
        $many = $this->queries(fn () => app(FinanceGoalService::class)->list($owner));

        $this->assertSame($one, $many, "goal {$one}/{$many}");
    }

    public function test_debt_api_list_and_grouped_totals_have_a_fixed_query_budget(): void
    {
        $owner = $this->owner();
        $owner->ensureProfile();
        $counterparty = FinanceCounterparty::factory()->create(['user_id' => $owner->id]);
        FinanceDebt::factory()->create(['user_id' => $owner->id, 'finance_counterparty_id' => $counterparty->id]);
        $this->actingAs($owner);

        $one = $this->queries(fn () => $this->getJson('/api/finance/debts')->assertOk());
        FinanceDebt::factory()->count(4)->create([
            'user_id' => $owner->id, 'finance_counterparty_id' => $counterparty->id,
        ]);
        $many = $this->queries(fn () => $this->getJson('/api/finance/debts')->assertOk());

        $this->assertSame($one, $many, "debt API totals {$one}/{$many}");
    }

    public function test_unified_goal_list_bulk_projects_finance_goals_with_a_fixed_query_budget(): void
    {
        $owner = $this->owner();
        $owner->ensureProfile();
        $account = $this->account($owner);
        $this->makeSaveGoal($owner->id, $account->id);
        $this->actingAs($owner);

        $one = $this->queries(fn () => $this->getJson('/api/goals')->assertOk());
        foreach (range(1, 4) as $_) {
            $this->makeSaveGoal($owner->id, $account->id);
        }
        $many = $this->queries(fn () => $this->getJson('/api/goals')->assertOk());

        $this->assertSame($one, $many, "unified finance goals {$one}/{$many}");
    }

    private function makeSaveGoal(int $userId, int $accountId): void
    {
        $fund = FinanceSavingFund::factory()->create(['user_id' => $userId, 'account_id' => $accountId]);
        $goal = Goal::factory()->create(['user_id' => $userId, 'type' => Goal::TYPE_FINANCE]);
        FinanceGoalDetail::factory()->create([
            'user_id' => $userId, 'goal_id' => $goal->id, 'kind' => 'save',
            'finance_saving_fund_id' => $fund->id, 'finance_debt_id' => null,
            'currency_code' => $fund->currency_code,
        ]);
    }

    private function queries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
