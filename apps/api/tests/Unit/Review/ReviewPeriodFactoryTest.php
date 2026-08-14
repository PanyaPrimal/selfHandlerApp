<?php

namespace Tests\Unit\Review;

use App\Services\Review\ReviewPeriodFactory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReviewPeriodFactoryTest extends TestCase
{
    #[DataProvider('periods')]
    public function test_it_derives_canonical_calendar_periods(
        string $type,
        string $anchor,
        string $start,
        string $end,
    ): void {
        $period = (new ReviewPeriodFactory)->make($type, $anchor, 'Europe/Kyiv');

        $this->assertSame([
            'type' => $type, 'anchor' => $anchor, 'start' => $start, 'end' => $end,
            'timezone' => 'Europe/Kyiv',
        ], $period->toArray());
    }

    /** @return iterable<string, array{string,string,string,string}> */
    public static function periods(): iterable
    {
        yield 'Sunday resolves to preceding Monday' => ['weekly', '2026-08-16', '2026-08-10', '2026-08-16'];
        yield 'cross-year ISO week' => ['weekly', '2027-01-01', '2026-12-28', '2027-01-03'];
        yield 'spring DST week remains calendar bounded' => ['weekly', '2026-03-29', '2026-03-23', '2026-03-29'];
        yield 'autumn DST week remains calendar bounded' => ['weekly', '2026-10-25', '2026-10-19', '2026-10-25'];
        yield 'leap February' => ['monthly', '2028-02-29', '2028-02-01', '2028-02-29'];
        yield 'non-leap February' => ['monthly', '2027-02-10', '2027-02-01', '2027-02-28'];
        yield 'thirty days' => ['monthly', '2026-04-30', '2026-04-01', '2026-04-30'];
        yield 'thirty-one days' => ['monthly', '2026-08-14', '2026-08-01', '2026-08-31'];
        yield 'daily' => ['daily', '2026-08-14', '2026-08-14', '2026-08-14'];
    }

    #[DataProvider('invalidValues')]
    public function test_it_rejects_unsupported_types_and_non_calendar_dates(string $type, string $anchor): void
    {
        $this->expectException(ValidationException::class);

        (new ReviewPeriodFactory)->make($type, $anchor, 'Europe/Kyiv');
    }

    /** @return iterable<array{string,string}> */
    public static function invalidValues(): iterable
    {
        yield ['yearly', '2026-08-14'];
        yield ['weekly', '2026-02-30'];
        yield ['monthly', '14-08-2026'];
    }
}
