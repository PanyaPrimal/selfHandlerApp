<?php

namespace Tests\Feature\Supplements;

class SupplementStockApiTest extends SupplementTestCase
{
    public function test_stock_facts_drive_exact_forecast_one_active_proposal_and_stable_dismissal(): void
    {
        $owner = $this->createUser();
        $supplement = $this->createSupplement($owner, [
            'restock_lead_days' => 7, 'package_quantity' => '30',
        ]);
        $this->createCourse($owner, $supplement);

        $response = $this->actingAs($owner)->postJson("/api/supplements/{$supplement->id}/stock-movements", [
            'kind' => 'restock', 'quantity' => '3', 'display_unit' => 'piece',
            'effective_on' => self::TODAY, 'reason' => null, 'note' => 'Opening pack',
        ])->assertCreated()
            ->assertJsonPath('stock.remaining_quantity', '3.000000')
            ->assertJsonPath('forecast.status', 'ready')
            ->assertJsonPath('forecast.runout_on', '2026-08-15')
            ->assertJsonPath('restock_proposal.suggested_quantity', '30.000000');
        $proposalId = $response->json('restock_proposal.id');
        $this->assertDatabaseCount('supplement_restock_proposals', 1);

        $this->actingAs($owner)->getJson("/api/supplements/{$supplement->id}/stock-movements")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.quantity_delta', '3.000000');

        $this->actingAs($owner)->patchJson("/api/supplement-restock-proposals/{$proposalId}", [
            'status' => 'dismissed',
        ])->assertOk()->assertJsonPath('data.status', 'dismissed');
        $this->actingAs($owner)->getJson('/api/supplements')
            ->assertOk()->assertJsonPath('data.0.restock_proposal', null);
        $this->assertDatabaseCount('supplement_restock_proposals', 1);

        $this->actingAs($owner)->postJson("/api/supplements/{$supplement->id}/stock-movements", [
            'kind' => 'correction', 'quantity' => '-1', 'display_unit' => 'piece',
            'effective_on' => self::TODAY, 'reason' => 'Counted one too many', 'note' => null,
        ])->assertCreated()
            ->assertJsonPath('stock.remaining_quantity', '2.000000')
            ->assertJsonPath('forecast.runout_on', '2026-08-14')
            ->assertJsonPath('restock_proposal.status', 'open');
        $this->assertDatabaseCount('supplement_restock_proposals', 2);
        $this->assertDatabaseCount('supplement_stock_movements', 2);
    }

    public function test_stock_requests_reject_invalid_future_unknown_and_foreign_facts(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $supplement = $this->createSupplement($owner);
        $base = [
            'kind' => 'correction', 'quantity' => '0', 'display_unit' => 'piece',
            'effective_on' => '2026-08-14', 'reason' => null, 'note' => null,
        ];

        $this->actingAs($owner)->postJson("/api/supplements/{$supplement->id}/stock-movements", $base)
            ->assertUnprocessable()->assertJsonValidationErrors(['quantity', 'effective_on', 'reason']);
        $this->actingAs($owner)->postJson("/api/supplements/{$supplement->id}/stock-movements", [
            ...$base, 'kind' => 'restock', 'quantity' => '-1', 'effective_on' => self::TODAY,
            'unknown' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['unknown']);
        $this->actingAs($other)->getJson("/api/supplements/{$supplement->id}/stock-movements")
            ->assertNotFound();
    }
}
