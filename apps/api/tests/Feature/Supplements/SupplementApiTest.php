<?php

namespace Tests\Feature\Supplements;

use App\Models\Supplement;

class SupplementApiTest extends SupplementTestCase
{
    public function test_user_creates_reads_corrects_archives_and_restores_exact_private_reference(): void
    {
        $owner = $this->createUser();
        $payload = [
            'name' => 'Vitamin D', 'category' => 'vitamin', 'form' => 'capsule',
            'stock_unit' => 'gram', 'preferred_display_unit' => 'mg',
            'usual_dose_quantity' => '25', 'package_quantity' => '1500',
            'restock_lead_days' => 10, 'note' => 'User-entered plan',
        ];

        $created = $this->actingAs($owner)->postJson('/api/supplements', $payload)
            ->assertCreated()
            ->assertJsonPath('data.usual_dose_quantity', '0.025000')
            ->assertJsonPath('data.package_quantity', '1.500000')
            ->assertJsonPath('data.stock.remaining_quantity', '0.000000')
            ->assertJsonPath('data.forecast.status', 'no_stock')
            ->json('data');

        $this->actingAs($owner)->getJson('/api/supplements')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.categories.0', 'vitamin');

        $this->actingAs($owner)->patchJson('/api/supplements/'.$created['id'], [
            'name' => 'Vitamin D3', 'is_archived' => true,
        ])->assertOk()->assertJsonPath('data.name', 'Vitamin D3')
            ->assertJsonPath('data.is_archived', true);

        $this->actingAs($owner)->getJson('/api/supplements')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($owner)->patchJson('/api/supplements/'.$created['id'], ['is_archived' => false])
            ->assertOk()->assertJsonPath('data.is_archived', false);
    }

    public function test_catalogue_requests_are_closed_validated_and_owner_isolated(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $supplement = Supplement::create([
            'user_id' => $owner->id, 'name' => 'Private', 'category' => 'other', 'form' => 'other',
            'stock_unit' => 'piece', 'preferred_display_unit' => 'piece',
            'usual_dose_quantity' => '1',
        ]);

        $this->actingAs($other)->patchJson('/api/supplements/'.$supplement->id, ['name' => 'Probe'])
            ->assertNotFound();
        $this->actingAs($other)->getJson('/api/supplements')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($owner)->postJson('/api/supplements', [
            'name' => 'Bad', 'category' => 'vitamin', 'form' => 'tablet',
            'stock_unit' => 'piece', 'preferred_display_unit' => 'mg',
            'usual_dose_quantity' => '1', 'package_quantity' => null,
            'restock_lead_days' => 7, 'note' => null, 'unknown' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['unknown']);
        $this->actingAs($owner)->postJson('/api/supplements', [
            'name' => 'Bad', 'category' => 'vitamin', 'form' => 'tablet',
            'stock_unit' => 'piece', 'preferred_display_unit' => 'mg',
            'usual_dose_quantity' => '1', 'package_quantity' => null,
            'restock_lead_days' => 7, 'note' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors(['preferred_display_unit']);
    }

    public function test_catalogue_requires_authentication(): void
    {
        $this->getJson('/api/supplements')->assertUnauthorized();
    }
}
