<?php

namespace Tests\Unit\Supplements;

use App\Services\SupplementRestockProposalService;
use Tests\Feature\Supplements\SupplementTestCase;

class SupplementRestockProposalServiceTest extends SupplementTestCase
{
    public function test_identity_is_unique_dismissal_is_stable_and_a_new_shortage_can_reopen(): void
    {
        $owner = $this->createUser();
        $supplement = $this->createSupplement($owner, ['package_quantity' => '30', 'restock_lead_days' => 7]);
        $supplement->setRelation('user', $owner);
        $service = app(SupplementRestockProposalService::class);
        $forecast = ['status' => 'ready', 'as_of' => self::TODAY, 'runout_on' => '2026-08-15'];

        $first = $service->reconcile($supplement, $forecast);
        $this->assertNotNull($first);
        $this->assertSame($first->id, $service->reconcile($supplement, $forecast)?->id);
        $this->assertDatabaseCount('supplement_restock_proposals', 1);

        $service->dismiss($first);
        $this->assertNull($service->reconcile($supplement, $forecast));
        $this->assertDatabaseCount('supplement_restock_proposals', 1);
        $this->assertDatabaseCount('recurring_rules', 0);

        $new = $service->reconcile($supplement, [
            'status' => 'ready', 'as_of' => self::TODAY, 'runout_on' => '2026-08-14',
        ]);
        $this->assertNotNull($new);
        $this->assertNotSame($first->id, $new->id);
        $this->assertDatabaseCount('supplement_restock_proposals', 2);
        $this->assertDatabaseCount('recurring_rules', 0);
    }
}
