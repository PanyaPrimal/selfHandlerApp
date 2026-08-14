<?php

namespace Tests\Feature\Review;

use App\Models\User;
use App\Services\Review\AggregateRegistry;
use App\Services\Review\ReviewWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReviewAggregateArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_exposes_each_module_once_in_stable_order(): void
    {
        $this->assertSame([
            'routines', 'sleep', 'workouts', 'nutrition', 'supplements', 'habits', 'planner', 'finance',
        ], app(AggregateRegistry::class)->keys());
    }

    public function test_review_controllers_and_composers_import_no_source_models(): void
    {
        foreach ([
            app_path('Http/Controllers/ReviewWorkspaceController.php'),
            app_path('Http/Controllers/PeriodicReviewController.php'),
            app_path('Services/Review/ReviewWorkspaceService.php'),
            app_path('Services/Review/AggregateRegistry.php'),
        ] as $path) {
            $source = file_get_contents($path);
            $this->assertIsString($source);
            foreach ([
                'Routine', 'SleepLog', 'WorkoutSession', 'Meal', 'Supplement', 'Habit', 'PlannedOccurrence',
                'FinanceLedgerEntry', 'Item', 'TimeBlock',
            ] as $model) {
                $this->assertStringNotContainsString("use App\\Models\\{$model};", $source, $path);
            }
        }
    }

    public function test_period_query_count_is_fixed_instead_of_scaling_with_calendar_days(): void
    {
        $owner = User::factory()->create();
        $owner->ensureProfile();
        $workspaces = app(ReviewWorkspaceService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $workspaces->periodic($owner, 'weekly', '2026-08-12');
        $weekly = count(DB::getQueryLog());

        DB::flushQueryLog();
        $workspaces->periodic($owner, 'monthly', '2026-08-12');
        $monthly = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($weekly, $monthly);
        $this->assertLessThanOrEqual(35, $monthly);
    }
}
