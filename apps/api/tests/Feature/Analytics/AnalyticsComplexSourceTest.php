<?php

namespace Tests\Feature\Analytics;

use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceExchangeRate;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\Item;
use App\Models\NutritionDailyTarget;
use App\Models\PlannedOccurrence;
use App\Models\Routine;
use App\Models\Supplement;
use App\Models\User;
use App\Models\WorkoutSession;
use App\Services\Finance\FinanceAnalyticsSeriesService;
use App\Services\Finance\FinanceLedgerService;
use App\Services\FoodCatalogueService;
use App\Services\HabitAnalyticsSeriesService;
use App\Services\HabitLogService;
use App\Services\HabitRecurrence;
use App\Services\MealService;
use App\Services\NutritionAnalyticsSeriesService;
use App\Services\Planner\PlannerAnalyticsSeriesService;
use App\Services\RoutineAnalyticsSeriesService;
use App\Services\RoutineRecurrence;
use App\Services\SupplementAnalyticsSeriesService;
use App\Services\SupplementCourseService;
use App\Services\WorkoutAnalyticsSeriesService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsComplexSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_routine_and_planner_use_effective_dates_once_with_item_and_owner_semantics(): void
    {
        CarbonImmutable::setTestNow('2026-08-12 09:00:00 UTC');
        $owner = $this->owner();
        $foreign = $this->owner();
        $routine = Routine::query()->create(['user_id' => $owner->id, 'name' => 'Daily plan']);
        app(RoutineRecurrence::class)->apply($routine, $owner, [
            'schedule_type' => 'daily', 'starts_on' => '2026-08-12',
        ], []);
        $ruleId = $routine->recurringRule()->value('id');
        PlannedOccurrence::query()->where('recurring_rule_id', $ruleId)
            ->where('occurrence_date', '2026-08-12')->update(['status' => PlannedOccurrence::STATUS_DONE]);
        PlannedOccurrence::query()->where('recurring_rule_id', $ruleId)
            ->where('occurrence_date', '2026-08-13')->update([
                'status' => PlannedOccurrence::STATUS_SKIPPED, 'rescheduled_to' => '2026-08-14',
            ]);
        Item::query()->create([
            'user_id' => $owner->id, 'title' => 'Done task', 'status' => Item::STATUS_DONE, 'due_on' => '2026-08-14',
        ]);
        Item::query()->create([
            'user_id' => $foreign->id, 'title' => 'Foreign task', 'status' => Item::STATUS_DONE, 'due_on' => '2026-08-14',
        ]);

        $routines = app(RoutineAnalyticsSeriesService::class)->daily($owner, '2026-08-12', '2026-08-14');
        $this->assertSame(['2026-08-12', '2026-08-14'], array_column($routines, 'date'));
        $this->assertSame(['1', '0'], array_column($routines, 'numerator'));
        $this->assertSame(['1', '2'], array_column($routines, 'denominator'));

        $planner = app(PlannerAnalyticsSeriesService::class)->daily($owner, '2026-08-14', '2026-08-14');
        $this->assertCount(1, $planner);
        $this->assertSame('1', $planner[0]['numerator']);
        $this->assertSame('3', $planner[0]['denominator']);
        $this->assertSame(3, $planner[0]['sample_count']);
    }

    public function test_habit_corrections_skips_and_archive_keep_only_durable_evidence(): void
    {
        CarbonImmutable::setTestNow('2026-08-12 09:00:00 UTC');
        $owner = $this->owner();
        $habit = Habit::query()->create([
            'user_id' => $owner->id, 'name' => 'Read', 'kind' => Habit::KIND_HABIT, 'mode' => Habit::MODE_YES_NO,
        ]);
        app(HabitRecurrence::class)->apply($habit, $owner, [
            'schedule_type' => 'daily', 'starts_on' => '2026-08-12',
        ], []);
        CarbonImmutable::setTestNow('2026-08-14 09:00:00 UTC');
        $logs = app(HabitLogService::class);
        $logs->upsert($habit, $owner, '2026-08-12', ['outcome' => HabitLog::OUTCOME_DONE, 'occurred_time' => '08:00']);
        $logs->upsert($habit, $owner, '2026-08-13', ['outcome' => HabitLog::OUTCOME_NOT_DONE, 'occurred_time' => '08:00']);
        $logs->upsert($habit, $owner, '2026-08-14', ['outcome' => HabitLog::OUTCOME_SKIPPED]);
        $habit->update(['is_active' => false, 'is_archived' => true, 'archived_at' => now()]);

        $service = app(HabitAnalyticsSeriesService::class);
        $before = $service->daily($owner, '2026-08-12', '2026-08-15');
        $this->assertSame(['2026-08-12', '2026-08-13', '2026-08-14'], array_column($before, 'date'));
        $this->assertSame(['1', '0', '0'], array_column($before, 'numerator'));

        $logs->upsert($habit, $owner, '2026-08-13', ['outcome' => HabitLog::OUTCOME_DONE, 'occurred_time' => '08:30']);
        $after = $service->daily($owner, '2026-08-13', '2026-08-13');
        $this->assertSame('1', $after[0]['numerator']);
        $this->assertSame('1', $after[0]['denominator']);
    }

    public function test_supplement_series_combines_schedule_due_time_and_durable_reschedule(): void
    {
        CarbonImmutable::setTestNow('2026-08-12 07:00:00 UTC');
        $owner = $this->owner();
        $supplement = Supplement::query()->create([
            'user_id' => $owner->id, 'name' => 'Capsules', 'category' => 'vitamin', 'form' => 'capsule',
            'stock_unit' => 'piece', 'preferred_display_unit' => 'piece', 'usual_dose_quantity' => '1',
        ]);
        $course = app(SupplementCourseService::class)->create($owner, [
            'supplement_id' => $supplement->id, 'goal_id' => null, 'name' => 'Twice daily',
            'dose_quantity' => '1', 'dose_display_unit' => 'piece',
            'starts_on' => '2026-08-12', 'ends_on' => '2026-08-14', 'is_active' => true,
            'schedule' => [
                'frequency' => 'daily', 'interval_count' => 1, 'weekdays' => [], 'cycle' => null,
                'slots' => [
                    ['slot' => 'morning', 'time' => '08:00', 'intake_context' => 'with_food'],
                    ['slot' => 'evening', 'time' => '20:00', 'intake_context' => 'with_food'],
                ],
            ],
        ]);
        CarbonImmutable::setTestNow('2026-08-13 12:00:00 UTC');
        $ruleId = $course->recurringRule()->value('id');
        PlannedOccurrence::query()->where('recurring_rule_id', $ruleId)
            ->where('occurrence_date', '2026-08-12')->where('slot', 'morning')
            ->update(['status' => PlannedOccurrence::STATUS_DONE]);

        $service = app(SupplementAnalyticsSeriesService::class);
        $before = $service->daily($owner, '2026-08-12', '2026-08-14');
        $this->assertSame(['2026-08-12', '2026-08-13'], array_column($before, 'date'));
        $this->assertSame(['1', '0'], array_column($before, 'numerator'));
        $this->assertSame(['2', '1'], array_column($before, 'denominator'));

        PlannedOccurrence::query()->where('recurring_rule_id', $ruleId)
            ->where('occurrence_date', '2026-08-13')->where('slot', 'morning')
            ->update(['status' => PlannedOccurrence::STATUS_DONE, 'rescheduled_to' => '2026-08-14']);
        $after = $service->daily($owner, '2026-08-13', '2026-08-14');
        $this->assertSame(['2026-08-14'], array_column($after, 'date'));
        $this->assertSame('1', $after[0]['numerator']);
        $this->assertSame('1', $after[0]['denominator']);
    }

    public function test_finance_uses_historical_fx_marks_whole_days_incomplete_and_keeps_reversals(): void
    {
        CarbonImmutable::setTestNow('2026-08-03 12:00:00 UTC');
        $owner = $this->owner('UAH');
        $usd = FinanceAccount::factory()->create(['user_id' => $owner->id, 'currency_code' => 'USD']);
        $eur = FinanceAccount::factory()->create(['user_id' => $owner->id, 'currency_code' => 'EUR']);
        $income = FinanceCategory::factory()->create(['user_id' => $owner->id, 'direction' => 'income']);
        $expense = FinanceCategory::factory()->create(['user_id' => $owner->id, 'direction' => 'expense']);
        FinanceExchangeRate::factory()->create([
            'user_id' => $owner->id, 'from_currency' => 'USD', 'to_currency' => 'UAH',
            'rate_date' => '2026-08-01', 'rate' => '40',
        ]);
        FinanceExchangeRate::factory()->create([
            'user_id' => $owner->id, 'from_currency' => 'USD', 'to_currency' => 'UAH',
            'rate_date' => '2026-08-02', 'rate' => '42',
        ]);
        $ledger = app(FinanceLedgerService::class);
        [$original] = $ledger->postActual($owner, [
            'idempotency_key' => 'analytics-income', 'kind' => 'income', 'account_id' => $usd->id,
            'category_id' => $income->id, 'amount' => '10', 'occurred_on' => '2026-08-01',
            'note' => null, 'tag' => null,
        ]);
        $ledger->postActual($owner, [
            'idempotency_key' => 'analytics-usd-expense', 'kind' => 'expense', 'account_id' => $usd->id,
            'category_id' => $expense->id, 'amount' => '5', 'occurred_on' => '2026-08-02',
            'note' => null, 'tag' => null,
        ]);
        $ledger->postActual($owner, [
            'idempotency_key' => 'analytics-eur-expense', 'kind' => 'expense', 'account_id' => $eur->id,
            'category_id' => $expense->id, 'amount' => '2', 'occurred_on' => '2026-08-02',
            'note' => null, 'tag' => null,
        ]);
        $ledger->reverse($owner, $original, ['idempotency_key' => 'analytics-reversal', 'reason' => 'Correction']);

        $series = app(FinanceAnalyticsSeriesService::class)->daily($owner, '2026-08-01', '2026-08-03');
        $this->assertSame('400.0000', $series['income'][0]['numerator']);
        $this->assertFalse($series['expense'][1]['complete']);
        $this->assertSame(2, $series['expense'][1]['sample_count']);
        $this->assertSame(['missing_fx:EUR'], $series['expense'][1]['reasons']);
        $this->assertSame('-420.0000', $series['income'][2]['numerator']);
    }

    public function test_nutrition_bounds_target_closeness_and_workout_corrections_are_live(): void
    {
        $owner = $this->owner();
        $food = app(FoodCatalogueService::class)->create($owner, [
            'name' => 'Test grain', 'basis_unit' => 'gram', 'is_beverage' => false,
            'calories_per_100' => 100, 'protein_per_100' => 0, 'fat_per_100' => 0,
            'carbs_per_100' => 0, 'quality_score' => 50, 'hydration_ratio' => 0,
        ]);
        foreach ([
            '2026-08-01' => 1500,
            '2026-08-02' => 3000,
            '2026-08-03' => 4500,
        ] as $date => $quantity) {
            NutritionDailyTarget::query()->create([
                'user_id' => $owner->id, 'target_date' => $date, 'status' => 'ready',
                'formula' => 'mifflin_st_jeor', 'calorie_target' => 2000, 'calculation_basis' => [],
            ]);
            app(MealService::class)->create($owner, [
                'consumed_on' => $date, 'name' => 'Evidence', 'category' => 'custom',
                'consumed_at_local' => '12:00', 'note' => null, 'submission_key' => (string) Str::uuid(),
                'entries' => [['food_item_id' => $food->id, 'recipe_id' => null, 'quantity' => $quantity]],
            ]);
        }

        $nutrition = app(NutritionAnalyticsSeriesService::class)->daily($owner, '2026-08-01', '2026-08-04');
        $this->assertSame(['75.0000000000', '50.0000000000', '0'], array_column($nutrition, 'numerator'));

        $session = WorkoutSession::query()->create([
            'user_id' => $owner->id, 'name' => 'Correction', 'workout_type' => 'strength',
            'outcome' => 'completed', 'performed_on' => '2026-08-01', 'duration_seconds' => 1800,
        ]);
        $workouts = app(WorkoutAnalyticsSeriesService::class);
        $this->assertSame('30.000000', $workouts->daily($owner, '2026-08-01', '2026-08-01')['duration'][0]['numerator']);
        $session->update(['duration_seconds' => 3600]);
        $this->assertSame('60.000000', $workouts->daily($owner, '2026-08-01', '2026-08-01')['duration'][0]['numerator']);
        $session->delete();
        $this->assertSame([], $workouts->daily($owner, '2026-08-01', '2026-08-01')['duration']);
    }

    private function owner(string $baseCurrency = 'UAH'): User
    {
        $user = User::factory()->create();
        $user->ensureProfile()->update(['timezone' => 'UTC', 'base_currency' => $baseCurrency]);
        $user->unsetRelation('profile');

        return $user->fresh();
    }
}
