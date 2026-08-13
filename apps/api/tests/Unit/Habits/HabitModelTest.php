<?php

namespace Tests\Unit\Habits;

use App\Models\Habit;
use App\Models\HabitLimitStep;
use App\Models\HabitLog;
use RuntimeException;
use Tests\Feature\Habits\HabitTestCase;

class HabitModelTest extends HabitTestCase
{
    public function test_every_new_model_requires_an_owner_and_refuses_owner_changes(): void
    {
        $this->expectException(RuntimeException::class);
        Habit::create(['name' => 'Ownerless', 'kind' => 'habit', 'mode' => 'yes_no']);
    }

    public function test_child_models_refuse_a_different_owner_from_the_habit(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $habit = $this->createHabit($owner);

        foreach ([HabitLog::class, HabitLimitStep::class] as $class) {
            try {
                $class::create($class === HabitLog::class ? [
                    'user_id' => $other->id,
                    'habit_id' => $habit->id,
                    'log_date' => self::TODAY,
                    'outcome' => HabitLog::OUTCOME_DONE,
                    'occurred_at' => now(),
                ] : [
                    'user_id' => $other->id,
                    'habit_id' => $habit->id,
                    'effective_on' => self::TODAY,
                    'limit_value' => 1,
                    'period' => HabitLimitStep::PERIOD_DAY,
                ]);
                $this->fail("{$class} accepted a foreign owner.");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_habit_refuses_foreign_or_inactive_context_links(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $foreignRoutine = $this->createRoutine($other);

        $this->expectException(RuntimeException::class);
        $this->createHabit($owner, ['routine_id' => $foreignRoutine->id]);
    }

    public function test_lifecycle_derives_archive_timestamp_and_preserves_active_choice(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner);

        $habit->applyLifecycle(['is_active' => false, 'is_archived' => true]);
        $habit->save();
        $this->assertFalse($habit->is_active);
        $this->assertTrue($habit->is_archived);
        $this->assertNotNull($habit->archived_at);

        $habit->applyLifecycle(['is_archived' => false]);
        $habit->save();
        $this->assertFalse($habit->is_active);
        $this->assertNull($habit->archived_at);
    }
}
