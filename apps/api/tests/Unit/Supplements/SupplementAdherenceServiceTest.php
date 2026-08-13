<?php

namespace Tests\Unit\Supplements;

use App\Services\SupplementAdherenceService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Supplements\SupplementTestCase;

class SupplementAdherenceServiceTest extends SupplementTestCase
{
    public function test_range_is_ordered_uses_elapsed_denominator_and_has_a_fixed_query_budget(): void
    {
        $owner = $this->createUser();
        $this->createCourse($owner);
        $this->createCourse($owner, $this->createSupplement($owner, ['name' => 'Second']));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = app(SupplementAdherenceService::class)
            ->forRange($owner, '2026-08-13', '2026-08-15');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(['2026-08-13', '2026-08-14', '2026-08-15'], array_column($result['days'], 'date'));
        $this->assertSame(2, $result['summary']['overdue']);
        $this->assertSame(2, $result['summary']['eligible']);
        $this->assertSame(0.0, $result['summary']['adherence_percentage']);
        $this->assertLessThanOrEqual(6, $queries, 'Adherence reads must not scale by course count.');
    }
}
