<?php

namespace Tests\Feature\Supplements;

use App\Models\SupplementRestockProposal;

class SupplementOwnershipTest extends SupplementTestCase
{
    public function test_foreign_reference_course_occurrence_stock_and_proposal_are_all_hidden(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $supplement = $this->createSupplement($owner);
        $course = $this->createCourse($owner, $supplement);
        $occurrence = $this->occurrence($course);
        $proposal = SupplementRestockProposal::create([
            'user_id' => $owner->id, 'supplement_id' => $supplement->id,
            'active_supplement_id' => $supplement->id, 'shortage_fingerprint' => hash('sha256', 'private'),
            'forecast_runout_on' => self::TODAY, 'needed_by' => self::TODAY,
            'suggested_quantity' => null, 'stock_unit' => 'piece', 'status' => 'open',
        ]);

        $this->actingAs($other)->patchJson("/api/supplements/{$supplement->id}", ['name' => 'Leaked'])->assertNotFound();
        $this->actingAs($other)->patchJson("/api/supplement-courses/{$course->id}", ['is_active' => false])->assertNotFound();
        $this->actingAs($other)->putJson("/api/supplement-occurrences/{$occurrence->id}/intake", [
            'outcome' => 'skipped', 'dose_quantity' => null, 'dose_display_unit' => null,
            'taken_time' => null, 'note' => null,
        ])->assertNotFound();
        $this->actingAs($other)->postJson("/api/supplements/{$supplement->id}/stock-movements", [
            'kind' => 'restock', 'quantity' => '1', 'display_unit' => 'piece',
            'effective_on' => self::TODAY, 'reason' => null, 'note' => null,
        ])->assertNotFound();
        $this->actingAs($other)->patchJson("/api/supplement-restock-proposals/{$proposal->id}", [
            'status' => 'dismissed',
        ])->assertNotFound();

        $this->assertSame('Capsules', $supplement->fresh()->name);
        $this->assertTrue($course->fresh()->is_active);
        $this->assertDatabaseCount('supplement_intakes', 0);
        $this->assertDatabaseCount('supplement_stock_movements', 0);
        $this->assertSame('open', $proposal->fresh()->status);
    }
}
