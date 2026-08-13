<?php

namespace Tests\Feature\Finance;

use Tests\Support\FinanceTestCase;

class FinanceRecurringOperationApiTest extends FinanceTestCase
{
    public function test_recurring_operation_create_list_update_and_foreign_boundary(): void
    {
        $this->freezeFinanceClock();
        $owner = $this->owner();
        $other = $this->owner();
        $account = $this->account($owner);
        $category = $this->category($owner, 'expense');
        $created = $this->actingAs($owner)->postJson('/api/finance/recurring-operations', [
            'name' => 'Rent', 'direction' => 'expense', 'account_id' => $account->id,
            'category_id' => $category->id, 'amount' => '1200', 'mandatory' => true,
            'starts_on' => '2026-08-01', 'ends_on' => null, 'interval_months' => 1,
            'month_days' => [5], 'reminder_time' => '08:00',
        ])->assertCreated()->assertJsonPath('data.rule.frequency', 'monthly')
            ->assertJsonPath('data.rule.month_days.0', 5);
        $id = $created->json('data.id');

        $this->actingAs($owner)->getJson('/api/finance/recurring-operations')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($other)->patchJson("/api/finance/recurring-operations/{$id}", ['active' => false])
            ->assertNotFound();
        $this->actingAs($owner)->patchJson("/api/finance/recurring-operations/{$id}", ['active' => false])
            ->assertOk()->assertJsonPath('data.active', false);
        $this->actingAs($owner)->patchJson("/api/finance/recurring-operations/{$id}", ['future' => true])
            ->assertUnprocessable()->assertJsonValidationErrorFor('request');
    }
}
