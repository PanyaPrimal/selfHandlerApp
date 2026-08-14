<?php

namespace Tests\Unit\Review;

use App\Services\Review\DayScoreService;
use PHPUnit\Framework\TestCase;

class DayScoreServiceTest extends TestCase
{
    public function test_it_composes_five_visible_equal_weight_components(): void
    {
        $score = (new DayScoreService)->compose($this->completeModules());

        $this->assertSame(71.67, $score['value']);
        $this->assertSame(5, $score['available_components']);
        $this->assertSame(5, $score['total_components']);
        $this->assertSame(100.0, $score['coverage_percentage']);
        $this->assertSame(
            ['nutrition', 'workouts', 'supplements', 'habits', 'planner'],
            array_column($score['components'], 'key'),
        );
        $this->assertSame([93.33, 50.0, 60.0, 75.0, 80.0], array_column($score['components'], 'value'));
        $this->assertSame([0.2, 0.2, 0.2, 0.2, 0.2], array_column($score['components'], 'weight'));
        $this->assertSame(array_fill(0, 5, 'available'), array_column($score['components'], 'reason'));
    }

    public function test_missing_evidence_is_named_and_excluded_without_becoming_failure(): void
    {
        $modules = $this->emptyModules();
        $modules['workouts']['unplanned'] = 1;
        $modules['workouts']['completed'] = 1;

        $score = (new DayScoreService)->compose($modules);

        $this->assertSame(100.0, $score['value']);
        $this->assertSame(1, $score['available_components']);
        $this->assertSame(20.0, $score['coverage_percentage']);
        $this->assertSame(
            ['no_target_evidence', 'available', 'no_scheduled_items', 'no_scheduled_items', 'no_planner_items'],
            array_column($score['components'], 'reason'),
        );
        $this->assertSame([0.0, 1.0, 0.0, 0.0, 0.0], array_column($score['components'], 'weight'));
    }

    public function test_no_available_component_returns_null_and_zero_coverage(): void
    {
        $score = (new DayScoreService)->compose($this->emptyModules());

        $this->assertNull($score['value']);
        $this->assertSame(0, $score['available_components']);
        $this->assertSame(0.0, $score['coverage_percentage']);
        $this->assertSame(array_fill(0, 5, null), array_column($score['components'], 'value'));
    }

    public function test_values_are_bounded_for_extreme_target_percentages(): void
    {
        $modules = $this->emptyModules();
        $modules['nutrition']['progress'] = [
            'calories' => ['percent' => '250.00'],
            'protein' => ['percent' => '250.00'],
            'fat' => ['percent' => '-20.00'],
            'carbs' => ['percent' => '100.00'],
            'hydration' => ['percent' => '-5.00'],
            'quality' => ['percent' => '500.00'],
        ];

        $score = (new DayScoreService)->compose($modules);

        $this->assertSame(50.0, $score['value']);
        $this->assertSame(50.0, $score['components'][0]['value']);
    }

    public function test_workout_component_distinguishes_planned_unplanned_and_absent_evidence(): void
    {
        $modules = $this->emptyModules();
        $modules['workouts'] = ['planned' => 4, 'completed' => 3, 'unplanned' => 0];
        $planned = (new DayScoreService)->compose($modules)['components'][1];
        $this->assertSame(75.0, $planned['value']);

        $modules['workouts'] = ['planned' => 0, 'completed' => 1, 'unplanned' => 1];
        $unplanned = (new DayScoreService)->compose($modules)['components'][1];
        $this->assertSame(100.0, $unplanned['value']);

        $modules['workouts'] = ['planned' => 0, 'completed' => 0, 'unplanned' => 0];
        $absent = (new DayScoreService)->compose($modules)['components'][1];
        $this->assertFalse($absent['available']);
        $this->assertSame('no_workout', $absent['reason']);
    }

    public function test_supplement_component_counts_every_resolved_and_pending_state_in_denominator(): void
    {
        $modules = $this->emptyModules();
        $modules['supplements'] = ['done' => 2, 'skipped' => 1, 'overdue' => 1, 'pending' => 1];

        $component = (new DayScoreService)->compose($modules)['components'][2];

        $this->assertSame(40.0, $component['value']);
        $this->assertTrue($component['available']);
    }

    public function test_habit_component_uses_successful_over_scheduled(): void
    {
        $modules = $this->emptyModules();
        $modules['habits'] = ['scheduled' => 3, 'successful' => 2];

        $component = (new DayScoreService)->compose($modules)['components'][3];

        $this->assertSame(66.67, $component['value']);
        $this->assertTrue($component['available']);
    }

    public function test_planner_component_excludes_time_blocks_from_the_eligible_denominator(): void
    {
        $modules = $this->emptyModules();
        $modules['planner'] = ['scheduled' => 2, 'done' => 1, 'time_blocks' => 99];

        $component = (new DayScoreService)->compose($modules)['components'][4];

        $this->assertSame(50.0, $component['value']);
        $this->assertTrue($component['available']);
    }

    /** @return array<string, array<string, mixed>> */
    private function completeModules(): array
    {
        return [
            'nutrition' => ['progress' => [
                'calories' => ['percent' => '90.00'], 'protein' => ['percent' => '120.00'],
                'fat' => ['percent' => '110.00'], 'carbs' => ['percent' => '100.00'],
                'hydration' => ['percent' => '80.00'], 'quality' => ['percent' => '105.00'],
            ]],
            'workouts' => ['planned' => 2, 'completed' => 1, 'unplanned' => 0],
            'supplements' => ['done' => 3, 'skipped' => 1, 'overdue' => 0, 'pending' => 1],
            'habits' => ['scheduled' => 4, 'successful' => 3],
            'planner' => ['scheduled' => 10, 'done' => 8],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function emptyModules(): array
    {
        return [
            'nutrition' => ['progress' => [
                'calories' => ['percent' => null], 'protein' => ['percent' => null],
                'fat' => ['percent' => null], 'carbs' => ['percent' => null],
                'hydration' => ['percent' => null], 'quality' => ['percent' => null],
            ]],
            'workouts' => ['planned' => 0, 'completed' => 0, 'unplanned' => 0],
            'supplements' => ['done' => 0, 'skipped' => 0, 'overdue' => 0, 'pending' => 0],
            'habits' => ['scheduled' => 0, 'successful' => 0],
            'planner' => ['scheduled' => 0, 'done' => 0],
        ];
    }
}
