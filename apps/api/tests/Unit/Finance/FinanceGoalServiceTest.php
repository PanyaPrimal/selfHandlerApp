<?php

namespace Tests\Unit\Finance;

use App\Models\Goal;
use App\Services\Finance\FinanceGoalService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\FinanceTestCase;

class FinanceGoalServiceTest extends FinanceTestCase
{
    public function test_create_and_update_replace_milestones_and_preserve_common_lifecycle(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $fund = $this->regularFund($owner, $this->account($owner), ['target_amount' => '1000.0000']);
        $service = app(FinanceGoalService::class);
        $goal = $service->create($owner, $this->savePayload($fund->id, [
            ['target_value' => '250.0000', 'target_date' => '2026-10-01'],
            ['target_value' => '750.0000', 'target_date' => null],
        ]));

        $this->assertSame(Goal::TYPE_FINANCE, $goal->type);
        $this->assertSame('save', $goal->financeDetail->kind);
        $this->assertSame($fund->id, $goal->financeDetail->finance_saving_fund_id);
        $this->assertNull($goal->financeDetail->finance_debt_id);
        $this->assertSame(['250.0000', '750.0000'], $goal->milestones->pluck('target_value')->all());

        $updated = $service->update($owner, $goal, [
            'name' => 'Completed reserve',
            'description' => null,
            'target_date' => '2026-12-31',
            'status' => 'completed',
            'archived' => true,
            'milestones' => [['target_value' => '500.0000', 'target_date' => null]],
        ]);
        $this->assertSame('Completed reserve', $updated->name);
        $this->assertSame('completed', $updated->status);
        $this->assertTrue($updated->is_archived);
        $this->assertNotNull($updated->completed_at);
        $this->assertNotNull($updated->archived_at);
        $this->assertSame(['500.0000'], $updated->milestones->pluck('target_value')->all());

        $reopened = $service->update($owner, $updated, ['status' => 'active', 'archived' => false]);
        $this->assertSame('active', $reopened->status);
        $this->assertFalse($reopened->is_archived);
        $this->assertNull($reopened->completed_at);
        $this->assertNull($reopened->archived_at);
    }

    public function test_create_rejects_foreign_inactive_duplicate_and_ambiguous_targets(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $service = app(FinanceGoalService::class);
        $foreignFund = $this->regularFund($other, $this->account($other));

        try {
            $service->create($owner, $this->savePayload($foreignFund->id));
            $this->fail('A foreign fund target was accepted.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $fund = $this->regularFund($owner, $this->account($owner));
        $fund->update(['is_active' => false]);
        $this->assertValidationFailure(fn () => $service->create($owner, $this->savePayload($fund->id)));

        $fund->update(['is_active' => true]);
        $service->create($owner, $this->savePayload($fund->id));
        $this->assertValidationFailure(fn () => $service->create($owner, $this->savePayload($fund->id)));

        $debtAccount = $this->account($owner);
        $debt = $this->flexibleDebt(
            $owner,
            $this->counterparty($owner),
            $debtAccount,
            $this->category($owner, 'expense'),
        );
        $ambiguous = $this->savePayload($fund->id);
        $ambiguous['debt_id'] = $debt->id;
        $this->assertValidationFailure(fn () => $service->create($owner, $ambiguous));
    }

    public function test_pay_off_milestones_decrease_and_failed_replacement_is_atomic(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $debt = $this->flexibleDebt(
            $owner,
            $this->counterparty($owner),
            $account,
            $this->category($owner, 'expense'),
        );
        $service = app(FinanceGoalService::class);
        $goal = $service->create($owner, [
            'name' => 'Debt free', 'description' => null, 'target_date' => null,
            'kind' => 'pay_off', 'saving_fund_id' => null, 'debt_id' => $debt->id,
            'milestones' => [
                ['target_value' => '800.0000', 'target_date' => null],
                ['target_value' => '200.0000', 'target_date' => null],
            ],
        ]);
        $this->assertSame('pay_off', $goal->financeDetail->kind);
        $this->assertSame(
            ['200.0000', '800.0000'],
            $goal->milestones->pluck('target_value')->sort()->values()->all(),
        );
        $this->assertSame(
            ['800.0000', '200.0000'],
            collect($service->one($owner, $goal)['milestones'])->pluck('target_value')->all(),
        );

        $this->assertValidationFailure(fn () => $service->update($owner, $goal, [
            'name' => 'Must roll back',
            'milestones' => [
                ['target_value' => '300.0000', 'target_date' => null],
                ['target_value' => '700.0000', 'target_date' => null],
            ],
        ]));
        $goal->refresh()->load('milestones');
        $this->assertSame('Debt free', $goal->name);
        $this->assertSame(
            ['200.0000', '800.0000'],
            $goal->milestones->pluck('target_value')->sort()->values()->all(),
        );
    }

    public function test_update_is_owner_scoped(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $fund = $this->regularFund($owner, $this->account($owner));
        $goal = app(FinanceGoalService::class)->create($owner, $this->savePayload($fund->id));

        $this->expectException(NotFoundHttpException::class);
        app(FinanceGoalService::class)->update($other, $goal, ['name' => 'Leaked']);
    }

    /** @param list<array{target_value:string,target_date:?string}> $milestones */
    private function savePayload(int $fundId, array $milestones = []): array
    {
        return [
            'name' => 'Reserve goal', 'description' => 'Build a reserve', 'target_date' => null,
            'kind' => 'save', 'saving_fund_id' => $fundId, 'debt_id' => null,
            'milestones' => $milestones,
        ];
    }

    private function assertValidationFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Invalid Finance goal data was accepted.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
