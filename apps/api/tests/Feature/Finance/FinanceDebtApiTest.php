<?php

namespace Tests\Feature\Finance;

use App\Models\FinanceCounterparty;
use App\Services\Finance\FinanceExchangeRateService;
use Tests\Support\FinanceTestCase;

class FinanceDebtApiTest extends FinanceTestCase
{
    public function test_counterparty_flexible_debt_payment_and_owner_boundary_are_closed(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $other = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $counterparty = $this->actingAs($owner)->postJson('/api/finance/counterparties', [
            'name' => 'Local bank', 'kind' => 'bank', 'note' => null,
        ])->assertCreated()->json('data');
        $this->actingAs($owner)->postJson('/api/finance/counterparties', [
            'name' => 'local BANK', 'kind' => 'bank', 'note' => null,
        ])->assertUnprocessable()->assertJsonValidationErrorFor('name');
        $debt = $this->actingAs($owner)->postJson('/api/finance/debts', [
            'name' => 'Personal loan', 'counterparty_id' => $counterparty['id'], 'direction' => 'owe',
            'repayment_mode' => 'flexible', 'original_amount' => '900.0000', 'currency' => 'UAH',
            'originated_on' => '2026-08-01', 'deadline' => null, 'account_id' => $account->id,
            'category_id' => $category->id, 'purchase_item_id' => null, 'schedule' => null, 'note' => null,
        ])->assertCreated()->assertJsonPath('data.remaining_amount', '900.0000')->json('data');
        $this->actingAs($owner)->postJson("/api/finance/debts/{$debt['id']}/payments", [
            'planned_occurrence_id' => null, 'amount' => '200.0000', 'account_id' => $account->id,
            'category_id' => $category->id, 'occurred_on' => '2026-08-13', 'idempotency_key' => 'api-debt-1', 'note' => null,
        ])->assertCreated()->assertJsonPath('debt.remaining_amount', '700.0000');
        $this->actingAs($owner)->getJson('/api/finance/debts')->assertOk()
            ->assertJsonPath('data.0.remaining_amount', '700.0000');
        $this->actingAs($other)->patchJson("/api/finance/debts/{$debt['id']}", ['name' => 'Leak'])->assertNotFound();
        $this->actingAs($owner)->patchJson("/api/finance/debts/{$debt['id']}", ['unknown' => true])
            ->assertUnprocessable()->assertJsonValidationErrorFor('request');
    }

    public function test_fixed_debt_api_returns_exact_short_month_schedule(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $counterparty = FinanceCounterparty::factory()->create(['user_id' => $owner->id]);
        $this->actingAs($owner)->postJson('/api/finance/debts', [
            'name' => 'Three parts', 'counterparty_id' => $counterparty->id, 'direction' => 'owe',
            'repayment_mode' => 'fixed', 'original_amount' => '300.0000', 'currency' => 'UAH',
            'originated_on' => '2026-01-01', 'deadline' => null, 'account_id' => $account->id,
            'category_id' => $category->id, 'purchase_item_id' => null, 'note' => null,
            'schedule' => ['installment_amount' => '100.0000', 'installment_count' => 3,
                'interval_months' => 1, 'monthday' => 31, 'first_due_on' => '2026-01-31', 'reminder_time' => null],
        ])->assertCreated()->assertJsonPath('data.occurrences.0.original_due_on', '2026-01-31')
            ->assertJsonPath('data.occurrences.1.original_due_on', '2026-03-31')
            ->assertJsonPath('data.occurrences.2.original_due_on', '2026-05-31');
    }

    public function test_purchase_installment_debt_is_the_only_finance_path_and_marks_purchase_bought(): void
    {
        $owner = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $counterparty = FinanceCounterparty::factory()->create(['user_id' => $owner->id]);
        $purchase = $this->actingAs($owner)->postJson('/api/storage/items', [
            'title' => 'New laptop', 'type' => 'purchase',
            'estimated_amount' => '1200.0000', 'estimated_currency_code' => 'UAH',
        ])->assertCreated()->assertJsonPath('data.status', 'active')->json('data');

        $payload = [
            'name' => 'Laptop installments', 'counterparty_id' => $counterparty->id, 'direction' => 'owe',
            'repayment_mode' => 'fixed', 'original_amount' => '1200.0000', 'currency' => 'UAH',
            'originated_on' => '2026-08-13', 'deadline' => null, 'account_id' => $account->id,
            'category_id' => $category->id, 'purchase_item_id' => $purchase['id'], 'note' => null,
            'schedule' => ['installment_amount' => '400.0000', 'installment_count' => 3,
                'interval_months' => 1, 'monthday' => 13, 'first_due_on' => '2026-08-13', 'reminder_time' => null],
        ];
        $debt = $this->actingAs($owner)->postJson('/api/finance/debts', $payload)
            ->assertCreated()->json('data');
        $this->assertDatabaseHas('items', ['id' => $purchase['id'], 'status' => 'done']);
        $this->assertDatabaseMissing('finance_transaction_groups', [
            'source_type' => 'purchase_item', 'source_id' => $purchase['id'],
        ]);

        $this->actingAs($owner)->postJson('/api/finance/source-expenses', [
            'source_type' => 'purchase_item', 'source_id' => $purchase['id'], 'account_id' => $account->id,
            'category_id' => $category->id, 'amount' => '1200.0000', 'occurred_on' => '2026-08-13',
            'idempotency_key' => 'purchase-after-debt', 'note' => null,
        ])->assertUnprocessable();

        foreach ($debt['occurrences'] as $index => $occurrence) {
            $this->actingAs($owner)->postJson("/api/finance/debts/{$debt['id']}/payments", [
                'planned_occurrence_id' => $occurrence['id'],
                'amount' => '400.0000',
                'account_id' => $account->id,
                'category_id' => $category->id,
                'occurred_on' => $occurrence['original_due_on'],
                'idempotency_key' => "purchase-installment-{$index}",
                'note' => null,
            ])->assertCreated();
        }
        $this->actingAs($owner)->patchJson("/api/finance/debts/{$debt['id']}", [
            'active' => false, 'archived' => true,
        ])->assertOk();

        $this->assertDatabaseHas('items', ['id' => $purchase['id'], 'status' => 'done']);
        $this->assertDatabaseHas('finance_debts', [
            'id' => $debt['id'], 'purchase_item_id' => $purchase['id'], 'is_archived' => true,
        ]);
    }

    public function test_debt_totals_convert_each_direction_to_profile_currency_or_fail_as_one_result(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $counterparty = FinanceCounterparty::factory()->create(['user_id' => $owner->id]);
        $usdAccount = $this->account($owner, 'USD');
        $oweCategory = $this->category($owner, 'expense');
        $owedCategory = $this->category($owner, 'income');
        foreach ([
            ['name' => 'USD loan', 'direction' => 'owe', 'amount' => '10.0000', 'category' => $oweCategory->id],
            ['name' => 'USD claim', 'direction' => 'owed_to_me', 'amount' => '5.0000', 'category' => $owedCategory->id],
        ] as $row) {
            $this->actingAs($owner)->postJson('/api/finance/debts', [
                'name' => $row['name'], 'counterparty_id' => $counterparty->id, 'direction' => $row['direction'],
                'repayment_mode' => 'flexible', 'original_amount' => $row['amount'], 'currency' => 'USD',
                'originated_on' => '2026-08-01', 'deadline' => null, 'account_id' => $usdAccount->id,
                'category_id' => $row['category'], 'purchase_item_id' => null, 'schedule' => null, 'note' => null,
            ])->assertCreated();
        }

        $this->actingAs($owner)->getJson('/api/finance/debts')->assertOk()
            ->assertJsonPath('totals.complete', false)
            ->assertJsonPath('totals.owe', null)
            ->assertJsonPath('totals.owed_to_me', null)
            ->assertJsonPath('totals.missing_currencies', ['USD']);

        app(FinanceExchangeRateService::class)->upsert($owner, [
            'from_currency' => 'USD', 'to_currency' => 'UAH', 'rate_date' => '2026-08-10',
            'rate' => '40.000000000000',
        ]);
        $this->actingAs($owner)->getJson('/api/finance/debts')->assertOk()
            ->assertJsonPath('totals.complete', true)
            ->assertJsonPath('totals.base_currency', 'UAH')
            ->assertJsonPath('totals.owe', '400.0000')
            ->assertJsonPath('totals.owed_to_me', '200.0000')
            ->assertJsonPath('totals.missing_currencies', []);
    }
}
