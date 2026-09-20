<?php

namespace Tests\Feature\Habits;

use App\Models\Habit;
use App\Models\HabitLog;

class HabitScheduleEditingTest extends HabitTestCase
{
    public function test_legacy_yes_no_form_saves_weekdays_and_time_with_null_target_fields(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner);
        $this->createLog($habit, $owner, self::TODAY, ['outcome' => HabitLog::OUTCOME_DONE, 'occurred_time' => '08:00']);
        $this->actingAs($owner);

        $this->patchJson("/api/habits/{$habit->id}", [
            'name' => 'River routine',
            'target_value' => null,
            'unit' => null,
            'schedule_type' => 'weekdays',
            'weekdays' => ['TU', 'TH', 'SA'],
            'preferred_time' => '07:30',
        ])->assertOk()
            ->assertJsonPath('data.schedule.weekdays', ['TU', 'TH', 'SA'])
            ->assertJsonPath('data.schedule.preferred_time', '07:30')
            ->assertJsonPath('data.target_value', null)
            ->assertJsonPath('data.unit', null);

        $this->assertDatabaseCount('habit_logs', 1);
        $this->getJson('/api/habits')->assertOk()
            ->assertJsonPath('data.0.schedule.weekdays', ['TU', 'TH', 'SA'])
            ->assertJsonPath('data.0.schedule.preferred_time', '07:30');
    }

    public function test_numeric_habit_with_history_accepts_unchanged_target_when_editing_schedule(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner, ['mode' => Habit::MODE_NUMERIC, 'target_value' => 20, 'unit' => 'pages']);
        $this->createLog($habit, $owner, self::TODAY, ['outcome' => HabitLog::OUTCOME_RECORDED, 'value' => 25, 'occurred_time' => '08:00']);
        $this->actingAs($owner);

        $this->patchJson("/api/habits/{$habit->id}", [
            'target_value' => 20,
            'unit' => 'pages',
            'preferred_time' => '21:15',
        ])->assertOk()->assertJsonPath('data.schedule.preferred_time', '21:15');

        $this->patchJson("/api/habits/{$habit->id}", ['target_value' => 30, 'unit' => 'minutes'])
            ->assertUnprocessable()->assertJsonValidationErrors(['target_value', 'unit']);
        $this->assertSame('20.000', $habit->fresh()->target_value);
        $this->assertSame('pages', $habit->fresh()->unit);
    }

    public function test_nonnumeric_modes_still_reject_actual_target_changes(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner);
        $this->actingAs($owner);
        $this->patchJson("/api/habits/{$habit->id}", ['target_value' => 2, 'unit' => 'times'])
            ->assertUnprocessable()->assertJsonValidationErrors(['target_value', 'unit']);
    }

    public function test_stepped_limit_schedule_accepts_its_unchanged_unit_but_not_a_new_unit(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner, ['kind' => Habit::KIND_ANTI_HABIT, 'mode' => Habit::MODE_STEPPED_LIMIT, 'unit' => 'cups']);
        $this->actingAs($owner);
        $this->patchJson("/api/habits/{$habit->id}", ['target_value' => null, 'unit' => 'cups', 'preferred_time' => '10:00'])
            ->assertOk()->assertJsonPath('data.schedule.preferred_time', '10:00');
        $this->patchJson("/api/habits/{$habit->id}", ['unit' => 'litres'])
            ->assertUnprocessable()->assertJsonValidationErrors('unit');
    }
}
