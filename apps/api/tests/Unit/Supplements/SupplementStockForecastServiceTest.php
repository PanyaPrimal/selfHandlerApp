<?php

namespace Tests\Unit\Supplements;

use App\Models\PlannedOccurrence;
use App\Models\Supplement;
use App\Models\SupplementStockMovement;
use App\Services\SupplementStockForecastService;
use App\Services\SupplementStockService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Supplements\SupplementTestCase;

class SupplementStockForecastServiceTest extends SupplementTestCase
{
    public function test_forecast_states_and_workspace_reads_have_a_fixed_query_budget(): void
    {
        $owner = $this->createUser();
        $empty = $this->createSupplement($owner, ['name' => 'Empty']);
        $stocked = $this->createSupplement($owner, ['name' => 'Stocked']);
        $course = $this->createCourse($owner, $stocked);
        SupplementStockMovement::create([
            'user_id' => $owner->id, 'supplement_id' => $stocked->id, 'kind' => 'restock',
            'quantity_delta' => '100.000000', 'effective_on' => self::TODAY, 'reason' => null, 'note' => null,
        ]);
        $supplements = $empty->newCollection([$empty, $stocked]);
        foreach ($supplements as $supplement) {
            $supplement->setRelation('user', $owner);
        }
        $stocks = app(SupplementStockService::class)->forMany($supplements);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $forecasts = app(SupplementStockForecastService::class)->forecastMany($supplements, $stocks);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame('no_stock', $forecasts[$empty->id]['status']);
        $this->assertSame('course_ends_with_stock', $forecasts[$stocked->id]['status']);
        $this->assertSame($course->ends_on->format('Y-m-d'), $forecasts[$stocked->id]['last_course_end']);
        $this->assertLessThanOrEqual(6, $queries, 'Forecast reads must not scale by supplement count.');

        SupplementStockMovement::create([
            'user_id' => $owner->id, 'supplement_id' => $stocked->id, 'kind' => 'correction',
            'quantity_delta' => '-100.000000', 'effective_on' => self::TODAY,
            'reason' => 'Inventory count', 'note' => null,
        ]);
        $this->assertSame('already_depleted', app(SupplementStockForecastService::class)
            ->forecast($stocked)['status']);
    }

    public function test_every_closed_forecast_state_has_a_reachable_domain_case(): void
    {
        $owner = $this->createUser();
        $forecast = app(SupplementStockForecastService::class);

        $none = $this->createSupplement($owner, ['name' => 'No facts']);
        $this->assertSame('no_stock', $forecast->forecast($none)['status']);

        $inactive = $this->createSupplement($owner, ['name' => 'No active course']);
        $this->stock($inactive->id, '10.000000');
        $this->assertSame('no_active_course', $forecast->forecast($inactive)['status']);

        $depleted = $this->createSupplement($owner, ['name' => 'Depleted']);
        $this->stock($depleted->id, '-1.000000', 'correction');
        $this->assertSame('already_depleted', $forecast->forecast($depleted)['status']);

        $ready = $this->createSupplement($owner, ['name' => 'Ready']);
        $this->createCourse($owner, $ready);
        $this->stock($ready->id, '2.000000');
        $readyResult = $forecast->forecast($ready);
        $this->assertSame('ready', $readyResult['status']);
        $this->assertSame('2026-08-14', $readyResult['runout_on']);

        $idle = $this->createSupplement($owner, ['name' => 'No consumption']);
        $idleCourse = $this->createCourse($owner, $idle);
        $this->stock($idle->id, '10.000000');
        PlannedOccurrence::query()
            ->where('recurring_rule_id', $idleCourse->recurringRule()->value('id'))
            ->update(['status' => PlannedOccurrence::STATUS_SKIPPED]);
        $this->assertSame('no_consumption', $forecast->forecast($idle)['status']);

        $ending = $this->createSupplement($owner, ['name' => 'Ends with stock']);
        $this->createCourse($owner, $ending);
        $this->stock($ending->id, '100.000000');
        $this->assertSame('course_ends_with_stock', $forecast->forecast($ending)['status']);

        $long = $this->createSupplement($owner, ['name' => 'Long horizon']);
        $this->createCourse($owner, $long, ['ends_on' => '2029-08-13']);
        $this->stock($long->id, '10000.000000');
        $longResult = $forecast->forecast($long);
        $this->assertSame('beyond_horizon', $longResult['status']);
        $this->assertSame('2028-08-11', $longResult['horizon_until']);
        $this->assertSame(730, $longResult['projected_occurrences']);
    }

    private function stock(int $supplementId, string $quantity, string $kind = 'restock'): void
    {
        SupplementStockMovement::create([
            'user_id' => Supplement::query()->findOrFail($supplementId)->user_id,
            'supplement_id' => $supplementId,
            'kind' => $kind,
            'quantity_delta' => $quantity,
            'effective_on' => self::TODAY,
            'reason' => $kind === 'correction' ? 'Inventory count' : null,
            'note' => null,
        ]);
    }
}
