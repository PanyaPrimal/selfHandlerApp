<?php

namespace Tests\Unit\Supplements;

use App\Models\SupplementStockMovement;
use App\Services\SupplementIntakeService;
use App\Services\SupplementStockService;
use Tests\Feature\Supplements\SupplementTestCase;

class SupplementStockServiceTest extends SupplementTestCase
{
    public function test_remaining_stock_is_exact_and_only_taken_intakes_consume_it(): void
    {
        $owner = $this->createUser();
        $supplement = $this->createSupplement($owner);
        $course = $this->createCourse($owner, $supplement);
        SupplementStockMovement::create([
            'user_id' => $owner->id, 'supplement_id' => $supplement->id, 'kind' => 'restock',
            'quantity_delta' => '3.000000', 'effective_on' => self::TODAY, 'reason' => null, 'note' => null,
        ]);
        $occurrence = $this->occurrence($course);
        app(SupplementIntakeService::class)->upsert($occurrence, $owner, [
            'outcome' => 'taken', 'dose_quantity' => '1', 'dose_display_unit' => 'piece',
            'taken_time' => '08:30', 'note' => null,
        ]);
        $stock = app(SupplementStockService::class)->forSupplement($supplement);
        $this->assertSame('2.000000', $stock['remaining_quantity']);

        app(SupplementIntakeService::class)->upsert($occurrence, $owner, [
            'outcome' => 'skipped', 'dose_quantity' => null, 'dose_display_unit' => null,
            'taken_time' => null, 'note' => null,
        ]);
        $this->assertSame('3.000000', app(SupplementStockService::class)
            ->forSupplement($supplement)['remaining_quantity']);
        $this->expectException(\RuntimeException::class);
        SupplementStockMovement::query()->firstOrFail()->update(['quantity_delta' => '4']);
    }
}
