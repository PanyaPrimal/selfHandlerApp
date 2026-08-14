<?php

namespace Tests\Feature\Analytics;

use App\Models\BodyMeasurement;
use App\Models\DailyReview;
use App\Models\SleepLog;
use App\Models\SleepPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_catalog_is_authenticated_closed_and_stably_ordered(): void
    {
        $this->getJson('/api/analytics/catalog')->assertUnauthorized();

        $this->actingAs(User::factory()->create())->getJson('/api/analytics/catalog')
            ->assertOk()
            ->assertJsonCount(17, 'data.metrics')
            ->assertJsonCount(3, 'data.correlations')
            ->assertJsonPath('data.metrics.0.key', 'routines.completion_rate')
            ->assertJsonPath('data.metrics.16.key', 'body.body_mass')
            ->assertJsonPath('data.limits.daily_days', 93)
            ->assertJsonPath('data.limits.monthly_days', 3653);
    }

    public function test_workspace_rolls_review_values_and_compares_the_adjacent_equal_range(): void
    {
        $owner = User::factory()->create();
        foreach ([
            '2026-07-30' => 4, '2026-07-31' => 6, '2026-08-01' => 8, '2026-08-02' => 10,
        ] as $date => $energy) {
            DailyReview::query()->create(['user_id' => $owner->id, 'review_date' => $date, 'energy' => $energy]);
        }

        $this->actingAs($owner)->getJson('/api/analytics/workspace?metric=review.energy&from=2026-08-01&to=2026-08-02&granularity=daily&compare=1')
            ->assertOk()
            ->assertJsonPath('data.period.from', '2026-08-01')
            ->assertJsonPath('data.period.to', '2026-08-02')
            ->assertJsonPath('data.points.0.value', '8.00')
            ->assertJsonPath('data.points.1.value', '10.00')
            ->assertJsonPath('data.trend.delta', '2.00')
            ->assertJsonPath('data.trend.slope_per_bucket', '2.00')
            ->assertJsonPath('data.comparison.current.value', '9.00')
            ->assertJsonPath('data.comparison.previous.from', '2026-07-30')
            ->assertJsonPath('data.comparison.previous.to', '2026-07-31')
            ->assertJsonPath('data.comparison.previous.value', '5.00')
            ->assertJsonPath('data.comparison.absolute_delta', '4.00')
            ->assertJsonPath('data.comparison.percentage_delta', '80.00');
    }

    public function test_sparse_body_corrections_and_deletion_are_live_and_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $measurement = BodyMeasurement::query()->create([
            'user_id' => $owner->id, 'metric' => 'body_mass', 'measured_on' => '2026-08-02', 'value' => '80000',
        ]);
        BodyMeasurement::query()->create([
            'user_id' => $foreign->id, 'metric' => 'body_mass', 'measured_on' => '2026-08-02', 'value' => '200000',
        ]);
        $path = '/api/analytics/workspace?metric=body.body_mass&from=2026-08-01&to=2026-08-03&granularity=daily&compare=0';

        $this->actingAs($owner)->getJson($path)
            ->assertOk()->assertJsonPath('data.points.1.value', '80.0000');

        $measurement->update(['value' => '79000']);
        $this->actingAs($owner)->getJson($path)
            ->assertOk()->assertJsonPath('data.points.1.value', '79.0000');

        $measurement->delete();
        $this->actingAs($owner)->getJson($path)
            ->assertOk()->assertJsonPath('data.trend.state', 'empty');
    }

    public function test_empty_counts_are_zero_while_missing_means_remain_empty(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->getJson('/api/analytics/workspace?metric=workouts.completed_sessions&from=2026-08-01&to=2026-08-02&granularity=daily&compare=0')
            ->assertOk()->assertJsonPath('data.points.0.value', '0')->assertJsonPath('data.trend.state', 'ready');
        $this->actingAs($owner)->getJson('/api/analytics/workspace?metric=sleep.duration_minutes&from=2026-08-01&to=2026-08-02&granularity=daily&compare=0')
            ->assertOk()->assertJsonPath('data.points.0.value', null)->assertJsonPath('data.trend.state', 'empty');
    }

    public function test_correlations_use_pairwise_owned_daily_aggregates_and_closed_unavailable_states(): void
    {
        $owner = User::factory()->create();
        $plan = SleepPlan::query()->create([
            'user_id' => $owner->id, 'name' => 'Analytics sleep', 'planned_wake_time' => '07:00',
        ]);
        for ($day = 1; $day <= 7; $day++) {
            $date = sprintf('2026-08-%02d', $day);
            SleepLog::query()->create([
                'user_id' => $owner->id, 'sleep_plan_id' => $plan->id, 'sleep_date' => $date,
                'actual_bed_at' => CarbonImmutable::parse($date.' 00:00:00', 'UTC'),
                'actual_wake_at' => CarbonImmutable::parse($date.' 0'.$day.':00:00', 'UTC'),
                'quality' => $day <= 5 ? $day : 5,
            ]);
            DailyReview::query()->create([
                'user_id' => $owner->id, 'review_date' => $date, 'energy' => $day,
                'mood' => $day <= 5 ? $day : 5, 'day_rating' => $day,
            ]);
        }

        $this->actingAs($owner)->getJson('/api/analytics/correlations?from=2026-08-01&to=2026-08-07')
            ->assertOk()
            ->assertJsonPath('data.findings.0.key', 'sleep_energy')
            ->assertJsonPath('data.findings.0.coefficient', '1.0000')
            ->assertJsonPath('data.findings.0.sample_count', 7)
            ->assertJsonPath('data.findings.1.state', 'ready')
            ->assertJsonPath('data.findings.2.state', 'unavailable')
            ->assertJsonPath('data.findings.2.reason', 'insufficient_samples');
    }

    public function test_strict_metric_date_granularity_and_range_bounds_are_rejected(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->getJson('/api/analytics/workspace?metric=unknown')->assertUnprocessable();
        $this->actingAs($owner)->getJson('/api/analytics/workspace?from=2026-02-30&to=2026-03-01')->assertUnprocessable();
        $this->actingAs($owner)->getJson('/api/analytics/workspace?granularity=quarterly')->assertUnprocessable();
        $this->actingAs($owner)->getJson('/api/analytics/workspace?from=2026-01-01&to=2026-05-01&granularity=daily')
            ->assertUnprocessable();
        $this->actingAs($owner)->getJson('/api/analytics/correlations?from=2025-01-01&to=2026-08-01')
            ->assertUnprocessable();
    }

    public function test_compare_accepts_openapi_boolean_and_numeric_query_forms_only(): void
    {
        $owner = User::factory()->create();
        $path = '/api/analytics/workspace?from=2026-08-01&to=2026-08-02';

        $this->actingAs($owner)->getJson($path.'&compare=true')->assertOk()
            ->assertJsonPath('data.comparison.current.from', '2026-08-01');
        $this->actingAs($owner)->getJson($path.'&compare=false')->assertOk()
            ->assertJsonPath('data.comparison', null);
        $this->actingAs($owner)->getJson($path.'&compare=1')->assertOk()
            ->assertJsonPath('data.comparison.current.from', '2026-08-01');
        $this->actingAs($owner)->getJson($path.'&compare=0')->assertOk()
            ->assertJsonPath('data.comparison', null);
        $this->actingAs($owner)->getJson($path.'&compare=yes')->assertUnprocessable();
    }

    public function test_default_workspace_uses_profile_today_and_thirty_inclusive_days(): void
    {
        CarbonImmutable::setTestNow('2026-08-14 21:30:00 UTC');
        $owner = User::factory()->create();
        $owner->ensureProfile()->update(['timezone' => 'Pacific/Auckland']);

        $this->actingAs($owner)->getJson('/api/analytics/workspace')->assertOk()
            ->assertJsonPath('data.period.from', '2026-07-17')
            ->assertJsonPath('data.period.to', '2026-08-15')
            ->assertJsonPath('data.period.timezone', 'Pacific/Auckland')
            ->assertJsonPath('data.metric.key', 'sleep.duration_minutes');
    }

    #[DataProvider('localizedRangeMessages')]
    public function test_range_validation_uses_the_profile_locale(string $locale, string $expected): void
    {
        $owner = User::factory()->create();
        $owner->ensureProfile()->update(['locale' => $locale]);

        $this->actingAs($owner)
            ->getJson('/api/analytics/workspace?from=2026-08-14&to=2026-01-01&granularity=daily')
            ->assertUnprocessable()
            ->assertJsonPath('errors.to.0', $expected);
    }

    public static function localizedRangeMessages(): array
    {
        return [
            'English' => ['en-GB', 'Choose an ordered Analytics date range of no more than 93 days.'],
            'Russian' => ['ru-UA', 'Выберите упорядоченный период аналитики не длиннее 93 дней.'],
            'Ukrainian' => ['uk-UA', 'Оберіть упорядкований період аналітики не довший за 93 днів.'],
        ];
    }

    public function test_responses_are_aggregate_only_and_exclude_private_source_fields(): void
    {
        $owner = User::factory()->create();
        DailyReview::query()->create([
            'user_id' => $owner->id,
            'review_date' => '2026-08-01',
            'energy' => 8,
            'notes' => 'private journal text',
        ]);

        $responses = [
            $this->actingAs($owner)->getJson('/api/analytics/catalog')->assertOk(),
            $this->actingAs($owner)->getJson('/api/analytics/workspace?metric=review.energy&from=2026-08-01&to=2026-08-01&compare=0')->assertOk(),
            $this->actingAs($owner)->getJson('/api/analytics/correlations?from=2026-08-01&to=2026-08-07')->assertOk(),
        ];

        foreach ($responses as $response) {
            $payload = $response->getContent();
            foreach (['"id":', '"note":', '"notes":', '"journal":', '"attachment":', '"transaction":', '"secret":'] as $privateField) {
                $this->assertStringNotContainsString($privateField, $payload);
            }
            $this->assertStringNotContainsString('private journal text', $payload);
        }
    }
}
