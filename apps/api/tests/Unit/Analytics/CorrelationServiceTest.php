<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\CorrelationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CorrelationServiceTest extends TestCase
{
    public function test_it_computes_pairwise_complete_pearson_and_ignores_unaligned_days(): void
    {
        $left = $this->points([1, 2, 3, 4, 5, 6, 7, 8, null]);
        $right = $this->points([2, 4, 6, 8, 10, 12, 14, 16, 99], 1);

        $finding = (new CorrelationService)->finding(
            $this->definition(), $left, $right, '2026-08-01', '2026-08-10',
        );

        $this->assertSame('ready', $finding['state']);
        $this->assertSame('1.0000', $finding['coefficient']);
        $this->assertSame('positive', $finding['direction']);
        $this->assertSame('strong', $finding['strength']);
        $this->assertSame(7, $finding['sample_count']);
    }

    public function test_it_returns_closed_unavailable_reasons(): void
    {
        $service = new CorrelationService;
        $insufficient = $service->finding(
            $this->definition(), $this->points([1, 2, 3]), $this->points([3, 2, 1]),
            '2026-08-01', '2026-08-03',
        );
        $this->assertSame('unavailable', $insufficient['state']);
        $this->assertSame('insufficient_samples', $insufficient['reason']);
        $this->assertNull($insufficient['coefficient']);

        $variance = $service->finding(
            $this->definition(), $this->points(array_fill(0, 7, 4)), $this->points([1, 2, 3, 4, 5, 6, 7]),
            '2026-08-01', '2026-08-07',
        );
        $this->assertSame('zero_variance', $variance['reason']);
        $this->assertNull($variance['direction']);
        $this->assertNull($variance['strength']);
    }

    #[DataProvider('strengthCases')]
    public function test_strength_thresholds_are_closed(float $coefficient, string $direction, string $strength): void
    {
        $classification = (new CorrelationService)->classify($coefficient);

        $this->assertSame($direction, $classification['direction']);
        $this->assertSame($strength, $classification['strength']);
    }

    public static function strengthCases(): array
    {
        return [
            'positive none' => [0.0999, 'none', 'none'],
            'positive weak boundary' => [0.1000, 'positive', 'weak'],
            'negative moderate boundary' => [-0.3000, 'negative', 'moderate'],
            'strong boundary' => [0.6000, 'positive', 'strong'],
        ];
    }

    private function definition(): array
    {
        return [
            'key' => 'sleep_energy', 'left_metric' => 'sleep.duration_minutes',
            'right_metric' => 'review.energy', 'minimum_samples' => 7,
        ];
    }

    private function points(array $values, int $dayOffset = 0): array
    {
        return array_map(fn ($value, $index): array => [
            'bucket_start' => sprintf('2026-08-%02d', $index + 1 + $dayOffset),
            'state' => $value === null ? 'empty' : 'ready',
            'value' => $value === null ? null : number_format($value, 2, '.', ''),
        ], $values, array_keys($values));
    }
}
