<?php

namespace Tests\Feature\Reports;

use App\Models\DailyReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_routes_are_authenticated_private_and_reuse_analytics_values(): void
    {
        $this->get('/api/reports/analytics.csv')->assertUnauthorized();
        $this->get('/api/reports/analytics.pdf')->assertUnauthorized();

        $owner = User::factory()->create();
        DailyReview::query()->create(['user_id' => $owner->id, 'review_date' => '2026-08-01', 'energy' => 8]);
        $query = '?metric=review.energy&from=2026-08-01&to=2026-08-01&granularity=daily&compare=0';

        $csv = $this->actingAs($owner)->get('/api/reports/analytics.csv'.$query)->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('8.00', $csv->getContent());
        $this->assertStringContainsString('no-store', (string) $csv->headers->get('Cache-Control'));

        $pdf = $this->actingAs($owner)->get('/api/reports/analytics.pdf'.$query)->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
    }

    public function test_report_query_validation_matches_analytics(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->get('/api/reports/analytics.csv?metric=unknown')->assertUnprocessable();
        $this->actingAs($owner)->get('/api/reports/analytics.pdf?from=2026-08-14&to=2026-01-01')->assertUnprocessable();
    }
}
