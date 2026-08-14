<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\MetricRollupService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MetricRollupServiceTest extends TestCase
{
    #[DataProvider('operatorCases')]
    public function test_it_rolls_up_each_closed_operator_without_averaging_percentages(
        array $definition,
        array $primitives,
        string $expected,
        ?string $numerator,
        ?string $denominator,
    ): void {
        $point = (new MetricRollupService)->point(
            $definition,
            $primitives,
            '2026-08-01',
            '2026-08-31',
        );

        $this->assertSame('ready', $point['state']);
        $this->assertSame($expected, $point['value']);
        $this->assertSame($numerator, $point['numerator']);
        $this->assertSame($denominator, $point['denominator']);
    }

    public static function operatorCases(): array
    {
        return [
            'sum' => [
                self::definition('sum', 0, true),
                [self::primitive('2026-08-02', '2'), self::primitive('2026-08-03', '3')],
                '5', null, null,
            ],
            'weighted mean' => [
                self::definition('mean', 2),
                [self::primitive('2026-08-02', '100', '1'), self::primitive('2026-08-03', '100', '3')],
                '50.00', '200.00', '4.00',
            ],
            'weighted percentage' => [
                self::definition('percentage', 2),
                [self::primitive('2026-08-02', '1', '2'), self::primitive('2026-08-03', '9', '10')],
                '83.33', '10.00', '12.00',
            ],
            'last sparse observation' => [
                self::definition('last', 4),
                [self::primitive('2026-08-02', '81.2500'), self::primitive('2026-08-20', '79.7500')],
                '79.7500', null, null,
            ],
        ];
    }

    public function test_empty_sum_is_a_real_zero_but_empty_mean_is_missing(): void
    {
        $service = new MetricRollupService;

        $this->assertSame(
            ['bucket_start' => '2026-08-01', 'bucket_end' => '2026-08-01', 'state' => 'ready',
                'value' => '0', 'sample_count' => 0, 'numerator' => null, 'denominator' => null, 'reasons' => []],
            $service->point(self::definition('sum', 0, true), [], '2026-08-01', '2026-08-01'),
        );
        $this->assertSame('empty', $service->point(
            self::definition('mean', 2), [], '2026-08-01', '2026-08-01',
        )['state']);
    }

    public function test_incomplete_evidence_invalidates_the_whole_bucket_with_sorted_unique_reasons(): void
    {
        $point = (new MetricRollupService)->point(self::definition('sum', 4, true), [
            self::primitive('2026-08-01', '10.0000'),
            self::primitive('2026-08-02', null, null, false, ['missing_fx:USD', 'missing_fx:EUR']),
            self::primitive('2026-08-03', null, null, false, ['missing_fx:USD']),
        ], '2026-08-01', '2026-08-03');

        $this->assertSame('incomplete', $point['state']);
        $this->assertNull($point['value']);
        $this->assertSame(1, $point['sample_count']);
        $this->assertSame(['missing_fx:EUR', 'missing_fx:USD'], $point['reasons']);
    }

    private static function definition(
        string $operator,
        int $precision,
        bool $emptyIsZero = false,
    ): array {
        return [
            'key' => 'test.metric', 'operator' => $operator, 'precision' => $precision,
            'empty_is_zero' => $emptyIsZero,
        ];
    }

    private static function primitive(
        string $date,
        ?string $numerator,
        ?string $denominator = null,
        bool $complete = true,
        array $reasons = [],
    ): array {
        return [
            'date' => $date, 'numerator' => $numerator, 'denominator' => $denominator,
            'sample_count' => $numerator === null ? 0 : 1, 'complete' => $complete, 'reasons' => $reasons,
        ];
    }
}
