<?php

namespace Tests\Feature\Analytics;

use App\Models\BodyMeasurement;
use App\Models\DailyReview;
use App\Models\NutritionDailyTarget;
use App\Models\SleepLog;
use App\Models\SleepPlan;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\Analytics\AnalyticsCatalog;
use App\Services\Analytics\AnalyticsRegistry;
use App\Services\BodyAnalyticsSeriesService;
use App\Services\NutritionAnalyticsSeriesService;
use App\Services\Review\ReviewAnalyticsSeriesService;
use App\Services\SleepAnalyticsSeriesService;
use App\Services\WorkoutAnalyticsSeriesService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnalyticsSourceContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_catalog_keys_are_served_and_query_count_is_independent_of_range_length(): void
    {
        $user = User::factory()->create();
        $user->ensureProfile();
        $keys = app(AnalyticsCatalog::class)->keys();
        $registry = app(AnalyticsRegistry::class);

        $short = $this->queryCount(fn () => $registry->daily($user, '2026-08-01', '2026-08-07', $keys));
        $long = $this->queryCount(fn () => $registry->daily($user, '2016-08-15', '2026-08-14', $keys));
        $series = $registry->daily($user, '2026-08-01', '2026-08-07', $keys);

        $this->assertSame($short, $long);
        $this->assertSame($keys, array_keys($series));
        $this->assertLessThanOrEqual(20, $short);
    }

    public function test_simple_owner_sources_emit_exact_aggregate_primitives(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $plan = SleepPlan::query()->create([
            'user_id' => $owner->id, 'name' => 'Owner sleep', 'planned_wake_time' => '07:00',
        ]);
        SleepLog::query()->create([
            'user_id' => $owner->id, 'sleep_plan_id' => $plan->id, 'sleep_date' => '2026-08-01',
            'actual_bed_at' => CarbonImmutable::parse('2026-07-31 22:30:00 UTC'),
            'actual_wake_at' => CarbonImmutable::parse('2026-08-01 06:00:00 UTC'), 'quality' => 4,
        ]);
        WorkoutSession::query()->create([
            'user_id' => $owner->id, 'name' => 'Run', 'workout_type' => 'endurance',
            'outcome' => 'completed', 'performed_on' => '2026-08-01', 'duration_seconds' => 2700,
        ]);
        NutritionDailyTarget::query()->create([
            'user_id' => $owner->id, 'target_date' => '2026-08-01', 'status' => 'ready',
            'formula' => 'mifflin_st_jeor', 'calorie_target' => 2000, 'calculation_basis' => [],
        ]);
        DailyReview::query()->create([
            'user_id' => $owner->id, 'review_date' => '2026-08-01', 'energy' => 8,
        ]);
        BodyMeasurement::query()->create([
            'user_id' => $owner->id, 'metric' => 'body_mass', 'measured_on' => '2026-08-01', 'value' => '81250',
        ]);
        BodyMeasurement::query()->create([
            'user_id' => $foreign->id, 'metric' => 'body_mass', 'measured_on' => '2026-08-01', 'value' => '499000',
        ]);

        $sleep = app(SleepAnalyticsSeriesService::class)->daily($owner, '2026-08-01', '2026-08-01');
        $this->assertSame('450', $sleep['duration'][0]['numerator']);
        $this->assertSame('4', $sleep['quality'][0]['numerator']);
        $workout = app(WorkoutAnalyticsSeriesService::class)->daily($owner, '2026-08-01', '2026-08-01');
        $this->assertSame('1', $workout['completed'][0]['numerator']);
        $this->assertSame('45.000000', $workout['duration'][0]['numerator']);
        $this->assertSame('0.0000000000', app(NutritionAnalyticsSeriesService::class)
            ->daily($owner, '2026-08-01', '2026-08-01')[0]['numerator']);
        $this->assertSame('8', app(ReviewAnalyticsSeriesService::class)
            ->daily($owner, '2026-08-01', '2026-08-01')['energy'][0]['numerator']);
        $this->assertSame('81.25000000', app(BodyAnalyticsSeriesService::class)
            ->daily($owner, '2026-08-01', '2026-08-01')[0]['numerator']);
    }

    private function queryCount(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
