<?php

namespace Tests\Feature\Finance;

use App\Models\Goal;
use App\Services\Finance\FinanceAccountService;
use App\Services\Finance\FinanceFundMovementService;
use App\Services\Finance\FinanceFundService;
use Tests\Support\FinanceTestCase;

class FinanceGoalApiTest extends FinanceTestCase
{
    public function test_save_goal_progress_and_milestones_derive_from_owned_fund(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $account = $this->account($owner);
        app(FinanceAccountService::class)->reconcile($account, $owner, [
            'observed_balance' => '1000.0000', 'occurred_on' => '2026-08-01', 'reason' => 'Opening', 'idempotency_key' => 'goal-open']);
        $fund = app(FinanceFundService::class)->create($owner, [
            'name' => 'Safety', 'fund_type' => 'regular', 'storage_mode' => 'virtual', 'account_id' => $account->id,
            'funding_account_id' => null, 'category_id' => null, 'currency' => 'UAH', 'target_mode' => 'explicit',
            'target_amount' => '800.0000', 'deadline' => null, 'rule' => ['top_up_mode' => 'none', 'fixed_amount' => null,
                'income_percent' => null, 'expense_months' => null, 'build_months' => null, 'starts_on' => null,
                'monthday' => null, 'reminder_time' => null], 'note' => null]);
        $goal = $this->actingAs($owner)->postJson('/api/finance/goals', [
            'name' => 'Safety first', 'description' => null, 'target_date' => null, 'kind' => 'save',
            'saving_fund_id' => $fund->id, 'debt_id' => null,
            'milestones' => [['target_value' => '200.0000', 'target_date' => null]],
        ])->assertCreated()->assertJsonPath('data.type', Goal::TYPE_FINANCE)
            ->assertJsonPath('data.current_value', '0.0000')->json('data');
        app(FinanceFundMovementService::class)->move($owner, $fund, ['action' => 'top_up', 'amount' => '250.0000',
            'counterparty_account_id' => null, 'occurred_on' => '2026-08-13', 'idempotency_key' => 'goal-save', 'note' => null]);
        $this->actingAs($owner)->getJson('/api/finance/goals')->assertOk()
            ->assertJsonPath('data.0.current_value', '250.0000')->assertJsonPath('data.0.milestones.0.achieved', true);
        $this->actingAs($owner)->postJson('/api/finance/goals', [
            'name' => 'Duplicate', 'description' => null, 'target_date' => null, 'kind' => 'save',
            'saving_fund_id' => $fund->id, 'debt_id' => null, 'milestones' => [],
        ])->assertUnprocessable();
        $this->actingAs($other)->patchJson("/api/finance/goals/{$goal['id']}", ['name' => 'Leak'])->assertNotFound();

        $this->actingAs($owner)->patchJson("/api/finance/goals/{$goal['id']}", [
            'milestones' => [
                ['target_value' => '400.0000', 'target_date' => null],
                ['target_value' => '200.0000', 'target_date' => null],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrorFor('milestones');
    }
}
