<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\DateBucketFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DateBucketFactoryTest extends TestCase
{
    #[DataProvider('bucketCases')]
    public function test_it_builds_clipped_profile_calendar_buckets(
        string $from,
        string $to,
        string $granularity,
        string $timezone,
        array $expected,
    ): void {
        $this->assertSame($expected, (new DateBucketFactory)->make($from, $to, $granularity, $timezone));
    }

    public static function bucketCases(): array
    {
        return [
            'daily leap day' => [
                '2024-02-28', '2024-03-01', 'daily', 'Europe/Kyiv',
                [
                    ['start' => '2024-02-28', 'end' => '2024-02-28'],
                    ['start' => '2024-02-29', 'end' => '2024-02-29'],
                    ['start' => '2024-03-01', 'end' => '2024-03-01'],
                ],
            ],
            'monday weeks clip edges' => [
                '2026-08-12', '2026-08-25', 'weekly', 'Europe/Kyiv',
                [
                    ['start' => '2026-08-12', 'end' => '2026-08-16'],
                    ['start' => '2026-08-17', 'end' => '2026-08-23'],
                    ['start' => '2026-08-24', 'end' => '2026-08-25'],
                ],
            ],
            'calendar months clip edges' => [
                '2026-01-30', '2026-03-02', 'monthly', 'Pacific/Auckland',
                [
                    ['start' => '2026-01-30', 'end' => '2026-01-31'],
                    ['start' => '2026-02-01', 'end' => '2026-02-28'],
                    ['start' => '2026-03-01', 'end' => '2026-03-02'],
                ],
            ],
        ];
    }

    public function test_previous_range_is_adjacent_and_has_the_same_inclusive_day_count(): void
    {
        $this->assertSame(
            ['from' => '2026-03-31', 'to' => '2026-04-30'],
            (new DateBucketFactory)->previousRange('2026-05-01', '2026-05-31', 'Europe/Kyiv'),
        );
    }
}
