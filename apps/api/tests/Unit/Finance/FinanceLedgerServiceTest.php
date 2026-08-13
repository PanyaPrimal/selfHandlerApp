<?php

namespace Tests\Unit\Finance;

use App\Services\Finance\FinanceLedgerService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Tests\Support\FinanceTestCase;

class FinanceLedgerServiceTest extends FinanceTestCase
{
    public function test_income_and_expense_create_one_exact_signed_entry_and_normalized_retry(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $income = $this->category($owner, 'income');
        $expense = $this->category($owner, 'expense');
        $service = app(FinanceLedgerService::class);

        [$incomeGroup, $created] = $service->postActual($owner, [
            'idempotency_key' => 'actual-income-1', 'kind' => 'income', 'account_id' => $account->id,
            'category_id' => $income->id, 'amount' => '10', 'occurred_on' => '2026-08-13',
            'note' => null, 'tag' => null,
        ]);
        [$retry, $retryCreated] = $service->postActual($owner, [
            'idempotency_key' => 'actual-income-1', 'kind' => 'income', 'account_id' => $account->id,
            'category_id' => $income->id, 'amount' => '10.0000', 'occurred_on' => '2026-08-13',
            'note' => null, 'tag' => null,
        ]);
        [$expenseGroup] = $service->postActual($owner, [
            'idempotency_key' => 'actual-expense-1', 'kind' => 'expense', 'account_id' => $account->id,
            'category_id' => $expense->id, 'amount' => '0.1251', 'occurred_on' => '2026-08-13',
            'note' => 'Coffee', 'tag' => 'food',
        ]);

        $this->assertTrue($created);
        $this->assertFalse($retryCreated);
        $this->assertSame($incomeGroup->id, $retry->id);
        $this->assertSame('10.0000', $incomeGroup->entries->sole()->delta_amount);
        $this->assertSame('-0.1251', $expenseGroup->entries->sole()->delta_amount);
    }

    public function test_actual_rejects_mismatched_or_archived_references_and_key_conflict(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $expense = $this->category($owner, 'expense');
        $service = app(FinanceLedgerService::class);
        $base = [
            'idempotency_key' => 'actual-conflict', 'kind' => 'income', 'account_id' => $account->id,
            'category_id' => $expense->id, 'amount' => '1.0000', 'occurred_on' => '2026-08-13',
            'note' => null, 'tag' => null,
        ];
        try {
            $service->postActual($owner, $base);
            $this->fail('A mismatched category direction must be refused.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('finance_transaction_groups', 0);
        }

        $income = $this->category($owner, 'income');
        $service->postActual($owner, [...$base, 'category_id' => $income->id]);
        $this->expectException(HttpResponseException::class);
        $service->postActual($owner, [...$base, 'category_id' => $income->id, 'amount' => '2.0000']);
    }
}
