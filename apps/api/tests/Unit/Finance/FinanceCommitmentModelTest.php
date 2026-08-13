<?php

namespace Tests\Unit\Finance;

use App\Models\FinanceCounterparty;
use App\Models\FinanceDebt;
use App\Models\FinanceFundOccurrenceFact;
use App\Models\FinanceGoalDetail;
use App\Models\FinanceSavingFund;
use App\Models\Goal;
use App\Models\Item;
use App\Models\RecurringRule;
use App\Services\Finance\FinanceAccountService;
use App\Services\Finance\FinanceDebtPaymentService;
use App\Services\Finance\FinanceFundMovementService;
use App\Services\Finance\FinanceOccurrenceService;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Tests\Support\FinanceTestCase;

class FinanceCommitmentModelTest extends FinanceTestCase
{
    public function test_new_aggregate_factories_are_owner_safe(): void
    {
        $owner = $this->owner();
        $counterparty = FinanceCounterparty::factory()->create(['user_id' => $owner->id]);
        $debt = FinanceDebt::factory()->create([
            'user_id' => $owner->id,
            'finance_counterparty_id' => $counterparty->id,
        ]);
        $fund = FinanceSavingFund::factory()->create(['user_id' => $owner->id]);
        $goal = Goal::factory()->create(['user_id' => $owner->id, 'type' => Goal::TYPE_FINANCE]);
        $detail = FinanceGoalDetail::factory()->create([
            'user_id' => $owner->id, 'goal_id' => $goal->id,
            'kind' => 'pay_off', 'finance_debt_id' => $debt->id,
        ]);

        $this->assertTrue($debt->isOwnedBy($owner));
        $this->assertTrue($fund->isOwnedBy($owner));
        $this->assertSame($goal->id, $detail->goal_id);
        $this->assertContains(RecurringRule::OWNER_FINANCE_DEBT, RecurringRule::OWNER_TYPES);
        $this->assertContains(Item::TYPE_PURCHASE, Item::TYPES);
    }

    public function test_finance_goal_detail_enforces_kind_xor_and_same_owner(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $debt = $this->flexibleDebt($owner, $this->counterparty($owner), $account, $category);
        $fund = $this->regularFund($owner, $account);
        $foreignFund = $this->regularFund($other, $this->account($other));
        $goal = Goal::factory()->create(['user_id' => $owner->id, 'type' => Goal::TYPE_FINANCE]);

        foreach ([
            [
                'user_id' => $owner->id, 'goal_id' => $goal->id, 'kind' => 'save',
                'finance_saving_fund_id' => $fund->id, 'finance_debt_id' => $debt->id,
                'currency_code' => 'UAH',
            ],
            [
                'user_id' => $owner->id, 'goal_id' => $goal->id, 'kind' => 'pay_off',
                'finance_saving_fund_id' => null, 'finance_debt_id' => null,
                'currency_code' => 'UAH',
            ],
            [
                'user_id' => $owner->id, 'goal_id' => $goal->id, 'kind' => 'save',
                'finance_saving_fund_id' => $foreignFund->id, 'finance_debt_id' => null,
                'currency_code' => 'UAH',
            ],
        ] as $attributes) {
            try {
                FinanceGoalDetail::query()->create($attributes);
                $this->fail('An invalid Finance goal detail was accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_payment_movement_and_occurrence_facts_are_append_only(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $debt = $this->flexibleDebt($owner, $this->counterparty($owner), $account, $category);
        [$payment] = app(FinanceDebtPaymentService::class)->pay($owner, $debt, [
            'planned_occurrence_id' => null,
            'amount' => '100.0000',
            'account_id' => $account->id,
            'category_id' => $category->id,
            'occurred_on' => '2026-08-13',
            'idempotency_key' => 'commitment-model-payment',
            'note' => null,
        ]);

        app(FinanceAccountService::class)->reconcile($account, $owner, [
            'observed_balance' => '500.0000',
            'occurred_on' => '2026-08-13',
            'reason' => 'Fund capacity',
            'idempotency_key' => 'commitment-model-opening',
        ]);
        $fund = $this->regularFund($owner, $account);
        [$movement] = app(FinanceFundMovementService::class)->move($owner, $fund, [
            'action' => 'top_up',
            'amount' => '50.0000',
            'counterparty_account_id' => null,
            'occurred_on' => '2026-08-13',
            'idempotency_key' => 'commitment-model-fund',
            'note' => null,
        ]);

        $scheduled = $this->regularFund($owner, $account, [
            'name' => 'Scheduled reserve',
            'rule' => [
                'top_up_mode' => 'fixed', 'fixed_amount' => '25.0000',
                'starts_on' => '2026-08-13', 'monthday' => 13,
            ],
        ]);
        $occurrence = $scheduled->recurringRule->occurrences()
            ->whereDate('occurrence_date', '2026-08-13')->sole();
        [$occurrenceFact] = app(FinanceOccurrenceService::class)->setOutcome($owner, $occurrence, 'actual');

        $this->assertAppendOnly($payment, 'principal_amount', '1.0000');
        $this->assertAppendOnly($movement, 'note', 'Changed');
        $this->assertAppendOnly($occurrenceFact, 'outcome', FinanceFundOccurrenceFact::OUTCOME_ACTUAL);
    }

    private function assertAppendOnly(Model $model, string $attribute, mixed $value): void
    {
        try {
            $model->forceFill([$attribute => $value])->save();
            $this->fail($model::class.' accepted an update.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $model->refresh();
        try {
            $model->delete();
            $this->fail($model::class.' accepted a deletion.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseHas($model->getTable(), ['id' => $model->getKey()]);
    }
}
