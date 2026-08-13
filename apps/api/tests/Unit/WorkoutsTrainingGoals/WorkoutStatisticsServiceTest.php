<?php

namespace Tests\Unit\WorkoutsTrainingGoals;

use App\Services\WorkoutSessionService;
use App\Services\WorkoutStatisticsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\WorkoutsTrainingGoals\WorkoutTestCase;

class WorkoutStatisticsServiceTest extends WorkoutTestCase
{
    public function test_summary_records_volume_distance_and_pace_come_from_completed_facts(): void
    {
        $owner = $this->createUser();
        $program = $this->createProgram($owner);
        $this->addPrescription($program);
        $this->createPlannedSession($program, $owner);
        app(WorkoutSessionService::class)->createManual($owner, [
            'name' => 'Easy 5K', 'workout_type' => 'cardio', 'performed_on' => self::TODAY,
            'started_time' => null, 'duration_seconds' => 1800, 'note' => null,
            'endurance' => [
                'activity' => 'running', 'run_type' => 'easy', 'distance_m' => 5000,
                'average_heart_rate' => null, 'energy_kcal' => null,
            ],
        ]);

        $result = app(WorkoutStatisticsService::class)->forRange($owner, self::TODAY, self::TODAY);

        $this->assertSame(1, $result['summary']['planned']);
        $this->assertSame(1, $result['summary']['completed']);
        $this->assertSame(0, $result['summary']['skipped']);
        $this->assertSame(1, $result['summary']['unplanned']);
        $this->assertSame(5400, $result['summary']['duration_seconds']);
        $this->assertSame(5000, $result['summary']['distance_m']);
        $this->assertSame('750.000', $result['summary']['strength_volume_kg']);
        $this->assertSame('50.000', $result['records']['exercises'][0]['max_weight_kg']);
        $this->assertSame(360, $result['records']['paces'][0]['best_pace_seconds_per_km']);
    }

    public function test_empty_range_is_honest_and_range_over_366_or_reversed_is_rejected(): void
    {
        $owner = $this->createUser();
        $service = app(WorkoutStatisticsService::class);
        $empty = $service->forRange($owner, '2026-08-01', self::TODAY);

        $this->assertSame([], $empty['sessions']);
        $this->assertSame(0, $empty['summary']['completed']);
        $this->assertSame([], $empty['records']['exercises']);

        foreach ([
            ['2026-08-14', self::TODAY],
            ['2025-01-01', '2026-08-13'],
        ] as [$from, $to]) {
            try {
                $service->forRange($owner, $from, $to);
                $this->fail('Expected invalid range.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_summary_query_count_is_bounded_as_session_and_set_count_grows(): void
    {
        $owner = $this->createUser();
        app(WorkoutSessionService::class)->createManual($owner, [
            'name' => 'Run', 'workout_type' => 'cardio', 'performed_on' => self::TODAY,
            'duration_seconds' => 1200,
            'endurance' => ['activity' => 'running', 'run_type' => 'easy', 'distance_m' => 3000],
        ]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(WorkoutStatisticsService::class)->forRange($owner, self::TODAY, self::TODAY);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(12, $queries);
    }
}
