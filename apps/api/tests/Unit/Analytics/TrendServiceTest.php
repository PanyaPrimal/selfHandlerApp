<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\TrendService;
use PHPUnit\Framework\TestCase;

class TrendServiceTest extends TestCase
{
    public function test_it_keeps_empty_insufficient_and_ready_states_distinct(): void
    {
        $service = new TrendService;

        $this->assertSame('empty', $service->summarize([$this->point(null)], 2)['state']);
        $this->assertSame('insufficient', $service->summarize([$this->point('0.00')], 2)['state']);

        $summary = $service->summarize([
            $this->point('10.00'), $this->point(null), $this->point('30.00'),
        ], 2);
        $this->assertSame('ready', $summary['state']);
        $this->assertSame(2, $summary['available_points']);
        $this->assertSame(3, $summary['total_buckets']);
        $this->assertSame('10.00', $summary['first']);
        $this->assertSame('30.00', $summary['last']);
        $this->assertSame('20.00', $summary['delta']);
        $this->assertSame('10.00', $summary['slope_per_bucket']);
    }

    public function test_comparison_guards_missing_and_previous_zero_percentage(): void
    {
        $service = new TrendService;
        $current = ['value' => '25.00'];

        $zero = $service->compare($current, ['value' => '0.00'], 2);
        $this->assertSame('25.00', $zero['absolute_delta']);
        $this->assertNull($zero['percentage_delta']);
        $this->assertSame('previous_zero', $zero['percentage_delta_reason']);

        $missing = $service->compare($current, ['value' => null], 2);
        $this->assertNull($missing['absolute_delta']);
        $this->assertSame('missing_value', $missing['percentage_delta_reason']);

        $ready = $service->compare($current, ['value' => '20.00'], 2);
        $this->assertSame('5.00', $ready['absolute_delta']);
        $this->assertSame('25.00', $ready['percentage_delta']);
        $this->assertSame('available', $ready['percentage_delta_reason']);
    }

    private function point(?string $value): array
    {
        return ['state' => $value === null ? 'empty' : 'ready', 'value' => $value];
    }
}
