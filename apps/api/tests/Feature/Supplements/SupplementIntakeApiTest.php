<?php

namespace Tests\Feature\Supplements;

use App\Models\SupplementStockMovement;

class SupplementIntakeApiTest extends SupplementTestCase
{
    public function test_taken_skip_correct_retry_and_clear_keep_one_fact_and_exact_stock(): void
    {
        $owner = $this->createUser();
        $course = $this->createCourse($owner);
        $occurrence = $this->occurrence($course);
        $payload = [
            'outcome' => 'taken', 'dose_quantity' => null, 'dose_display_unit' => null,
            'taken_time' => '08:30', 'note' => 'After breakfast',
        ];

        $this->actingAs($owner)->putJson("/api/supplement-occurrences/{$occurrence->id}/intake", $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.intake.dose_quantity', '1.000000')
            ->assertJsonPath('stock.remaining_quantity', '-1.000000')
            ->assertJsonPath('forecast.status', 'already_depleted');
        $this->actingAs($owner)->putJson("/api/supplement-occurrences/{$occurrence->id}/intake", $payload)
            ->assertOk();
        $this->assertDatabaseCount('supplement_intakes', 1);

        $this->actingAs($owner)->putJson("/api/supplement-occurrences/{$occurrence->id}/intake", [
            'outcome' => 'taken', 'dose_quantity' => '2', 'dose_display_unit' => 'piece',
            'taken_time' => '08:45', 'note' => null,
        ])->assertOk()->assertJsonPath('stock.remaining_quantity', '-2.000000');

        $this->actingAs($owner)->putJson("/api/supplement-occurrences/{$occurrence->id}/intake", [
            'outcome' => 'skipped', 'dose_quantity' => null, 'dose_display_unit' => null,
            'taken_time' => null, 'note' => 'Deliberate skip',
        ])->assertOk()->assertJsonPath('data.status', 'skipped')
            ->assertJsonPath('stock.remaining_quantity', '0.000000')
            ->assertJsonPath('forecast.status', 'no_stock');

        $this->actingAs($owner)->deleteJson("/api/supplement-occurrences/{$occurrence->id}/intake")
            ->assertNoContent();
        $this->assertDatabaseCount('supplement_intakes', 0);
        $this->assertDatabaseHas('planned_occurrences', [
            'id' => $occurrence->id, 'status' => 'planned', 'supplement_intake_id' => null,
        ]);
    }

    public function test_intake_rejects_future_invalid_foreign_and_unknown_actions(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $course = $this->createCourse($owner, attributes: [
            'starts_on' => '2026-08-14', 'ends_on' => '2026-08-15',
        ]);
        $future = $this->occurrence($course, '2026-08-14');

        $this->actingAs($owner)->putJson("/api/supplement-occurrences/{$future->id}/intake", [
            'outcome' => 'taken', 'dose_quantity' => null, 'dose_display_unit' => null,
            'taken_time' => '08:00', 'note' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors(['taken_time']);
        $this->actingAs($other)->putJson("/api/supplement-occurrences/{$future->id}/intake", [
            'outcome' => 'skipped', 'dose_quantity' => null, 'dose_display_unit' => null,
            'taken_time' => null, 'note' => null,
        ])->assertNotFound();
        $todayCourse = $this->createCourse($owner, $this->createSupplement($owner, ['name' => 'Other']));
        $today = $this->occurrence($todayCourse);
        $this->actingAs($owner)->putJson("/api/supplement-occurrences/{$today->id}/intake", [
            'outcome' => 'taken', 'dose_quantity' => null, 'dose_display_unit' => null,
            'taken_time' => '08:00', 'note' => null, 'unknown' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['unknown']);
    }

    public function test_clearing_an_intake_reconciles_its_stale_restock_proposal(): void
    {
        $owner = $this->createUser();
        $supplement = $this->createSupplement($owner);
        $course = $this->createCourse($owner, $supplement);
        $occurrence = $this->occurrence($course);
        SupplementStockMovement::create([
            'user_id' => $owner->id,
            'supplement_id' => $supplement->id,
            'kind' => 'restock',
            'quantity_delta' => '10.000000',
            'effective_on' => self::TODAY,
            'reason' => null,
            'note' => null,
        ]);

        $this->actingAs($owner)->putJson("/api/supplement-occurrences/{$occurrence->id}/intake", [
            'outcome' => 'taken',
            'dose_quantity' => '10',
            'dose_display_unit' => 'piece',
            'taken_time' => '08:30',
            'note' => null,
        ])->assertCreated()->assertJsonPath('restock_proposal.status', 'open');

        $this->actingAs($owner)->deleteJson("/api/supplement-occurrences/{$occurrence->id}/intake")
            ->assertNoContent();
        $this->assertDatabaseHas('supplement_restock_proposals', [
            'supplement_id' => $supplement->id,
            'status' => 'resolved',
        ]);
    }
}
