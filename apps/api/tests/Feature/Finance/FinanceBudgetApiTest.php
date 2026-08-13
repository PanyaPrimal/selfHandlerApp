<?php

namespace Tests\Feature\Finance;

use Tests\Support\FinanceTestCase;

class FinanceBudgetApiTest extends FinanceTestCase
{
    public function test_budget_crud_is_closed_and_owner_scoped(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $category = $this->category($owner, 'expense');
        $created = $this->actingAs($owner)->postJson('/api/finance/budgets', [
            'month' => '2026-08', 'category_id' => $category->id,
            'limit_amount' => '1000', 'currency' => 'UAH',
        ])->assertCreated()->assertJsonPath('data.limit_amount', '1000.0000')
            ->assertJsonPath('data.actual_amount', '0.0000');
        $id = $created->json('data.id');

        $this->actingAs($other)->patchJson("/api/finance/budgets/{$id}", ['limit_amount' => '1'])
            ->assertNotFound();
        $this->actingAs($owner)->patchJson("/api/finance/budgets/{$id}", ['future' => true])
            ->assertUnprocessable()->assertJsonValidationErrorFor('request');
        $this->actingAs($owner)->patchJson("/api/finance/budgets/{$id}", ['limit_amount' => '900.125'])
            ->assertOk()->assertJsonPath('data.limit_amount', '900.1250');
        $this->actingAs($owner)->getJson('/api/finance/budgets?month=2026-08')
            ->assertOk()->assertJsonPath('month', '2026-08')->assertJsonCount(1, 'data');
        $this->actingAs($owner)->deleteJson("/api/finance/budgets/{$id}")->assertNoContent();
    }
}
