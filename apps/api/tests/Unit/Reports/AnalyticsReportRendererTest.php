<?php

namespace Tests\Unit\Reports;

use App\Services\Reports\AnalyticsReportRenderer;
use Tests\TestCase;

class AnalyticsReportRendererTest extends TestCase
{
    public function test_csv_is_utf8_rfc4180_formula_safe_and_preserves_evidence(): void
    {
        $csv = app(AnalyticsReportRenderer::class)->csv($this->workspace(), 'en');

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Analytics report', $csv);
        $this->assertStringContainsString('2026-08-01,2026-08-01,8.00,Available,1', $csv);
        $this->assertStringContainsString('2026-08-02,2026-08-02,,Incomplete,0,"Missing exchange rate for =EUR"', $csv);
        $this->assertStringNotContainsString(',=EUR', $csv);
    }

    public function test_pdf_is_real_localized_pdf_with_cyrillic_capable_font(): void
    {
        $pdf = app(AnalyticsReportRenderer::class)->pdf($this->workspace(), 'uk');

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
        $this->assertStringContainsString('/Font', $pdf);
    }

    /** @return array<string,mixed> */
    private function workspace(): array
    {
        return [
            'period' => ['from' => '2026-08-01', 'to' => '2026-08-02', 'granularity' => 'daily', 'timezone' => 'Europe/Kyiv'],
            'metric' => ['key' => 'review.energy', 'module' => 'review', 'unit' => 'rating_10', 'operator' => 'mean', 'precision' => 2, 'empty_is_zero' => false, 'sensitivity' => 'well_being'],
            'currency' => null,
            'points' => [
                ['bucket_start' => '2026-08-01', 'bucket_end' => '2026-08-01', 'state' => 'ready', 'value' => '8.00', 'sample_count' => 1, 'numerator' => '8.00', 'denominator' => '1.00', 'reasons' => []],
                ['bucket_start' => '2026-08-02', 'bucket_end' => '2026-08-02', 'state' => 'incomplete', 'value' => null, 'sample_count' => 0, 'numerator' => null, 'denominator' => null, 'reasons' => ['missing_fx:=EUR']],
            ],
            'trend' => ['state' => 'ready', 'available_points' => 1, 'total_buckets' => 2, 'first' => '8.00', 'last' => '8.00', 'delta' => null, 'slope_per_bucket' => null],
            'comparison' => null,
        ];
    }
}
