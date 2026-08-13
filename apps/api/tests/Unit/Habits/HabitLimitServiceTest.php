<?php

namespace Tests\Unit\Habits;

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\User;
use App\Services\HabitLimitService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Habits\HabitTestCase;

class HabitLimitServiceTest extends HabitTestCase
{
    public function test_normalized_reduction_accepts_day_to_week_transition_and_derives_statuses(): void
    {
        [$owner, $habit] = $this->steppedHabit();

        $steps = $this->service()->replace($habit, $owner, [
            ['effective_on' => '2026-08-01', 'limit_value' => 1, 'period' => 'day'],
            ['effective_on' => '2026-08-10', 'limit_value' => 5, 'period' => 'week'],
            ['effective_on' => '2026-09-01', 'limit_value' => 3, 'period' => 'week'],
        ]);

        $this->assertSame(['completed', 'current', 'upcoming'], $steps->pluck('status')->all());
        $this->assertDatabaseCount('habit_limit_steps', 3);
    }

    public function test_invalid_plan_is_rejected_atomically(): void
    {
        [$owner, $habit] = $this->steppedHabit();
        $service = $this->service();
        $service->replace($habit, $owner, [
            ['effective_on' => '2026-08-01', 'limit_value' => 5, 'period' => 'week'],
        ]);

        try {
            $service->replace($habit, $owner, [
                ['effective_on' => '2026-08-10', 'limit_value' => 3, 'period' => 'week'],
                ['effective_on' => '2026-08-10', 'limit_value' => 4, 'period' => 'week'],
            ]);
            $this->fail('Invalid ladder was accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('habit_limit_steps', 1);
            $this->assertDatabaseHas('habit_limit_steps', ['limit_value' => 5]);
        }
    }

    public function test_daily_limit_reports_consumed_remaining_and_exceeded(): void
    {
        [$owner, $habit] = $this->steppedHabit();
        $this->service()->replace($habit, $owner, [
            ['effective_on' => self::TODAY, 'limit_value' => 2, 'period' => 'day'],
        ]);
        $this->log($habit, $owner->id, self::TODAY, 3);

        $status = $this->service()->status($habit, self::TODAY);

        $this->assertSame('exceeded', $status['state']);
        $this->assertEqualsWithDelta(3, $status['consumed'], 0.001);
        $this->assertEqualsWithDelta(0, $status['remaining'], 0.001);
        $this->assertFalse($status['within_limit']);
        $this->assertSame(self::TODAY, $status['period_from']);
        $this->assertSame(self::TODAY, $status['period_to']);
    }

    public function test_weekly_limit_uses_monday_through_sunday_and_includes_equality(): void
    {
        [$owner, $habit] = $this->steppedHabit();
        $this->service()->replace($habit, $owner, [
            ['effective_on' => '2026-08-01', 'limit_value' => 5, 'period' => 'week'],
        ]);
        $this->log($habit, $owner->id, '2026-08-10', 2);
        $this->log($habit, $owner->id, '2026-08-13', 3);
        $this->log($habit, $owner->id, '2026-08-09', 100);

        $status = $this->service()->status($habit, self::TODAY);

        $this->assertSame('within', $status['state']);
        $this->assertEqualsWithDelta(5, $status['consumed'], 0.001);
        $this->assertEqualsWithDelta(0, $status['remaining'], 0.001);
        $this->assertTrue($status['within_limit']);
        $this->assertSame('2026-08-10', $status['period_from']);
        $this->assertSame('2026-08-16', $status['period_to']);
    }

    public function test_future_first_step_reports_no_active_ceiling_without_claiming_success(): void
    {
        [$owner, $habit] = $this->steppedHabit();
        $this->service()->replace($habit, $owner, [
            ['effective_on' => '2026-09-01', 'limit_value' => 3, 'period' => 'week'],
        ]);

        $status = $this->service()->status($habit, self::TODAY);

        $this->assertSame('no_active_step', $status['state']);
        $this->assertNull($status['period_from']);
        $this->assertNull($status['remaining']);
        $this->assertNull($status['within_limit']);
        $this->assertSame('upcoming', $status['step']['status']);
    }

    public function test_many_limit_statuses_use_a_fixed_query_budget(): void
    {
        $owner = $this->createUser();
        $ids = [];
        for ($index = 0; $index < 15; $index++) {
            $habit = $this->createHabit($owner, [
                'name' => "Limit {$index}",
                'kind' => Habit::KIND_ANTI_HABIT,
                'mode' => Habit::MODE_STEPPED_LIMIT,
                'unit' => 'units',
            ]);
            $this->service()->replace($habit, $owner, [
                ['effective_on' => '2026-08-01', 'limit_value' => 5, 'period' => 'week'],
            ]);
            $ids[] = $habit->id;
        }

        $habits = Habit::query()->whereIn('id', $ids)->get();
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $statuses = $this->service()->statuses($habits, self::TODAY);
            $queries = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        $this->assertCount(15, $statuses);
        $this->assertLessThanOrEqual(3, $queries);
    }

    /** @return array{User, Habit} */
    private function steppedHabit(): array
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner, [
            'kind' => Habit::KIND_ANTI_HABIT,
            'mode' => Habit::MODE_STEPPED_LIMIT,
            'unit' => 'drinks',
        ]);

        return [$owner, $habit];
    }

    private function service(): HabitLimitService
    {
        return app(HabitLimitService::class);
    }

    private function log(Habit $habit, int $userId, string $date, float $value): void
    {
        HabitLog::create([
            'user_id' => $userId,
            'habit_id' => $habit->id,
            'log_date' => $date,
            'outcome' => HabitLog::OUTCOME_RECORDED,
            'value' => $value,
            'occurred_at' => $date.' 08:00:00',
        ]);
    }
}
