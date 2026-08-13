<?php

namespace Tests\Feature\Habits;

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\PlannedOccurrence;

class HabitApiTest extends HabitTestCase
{
    public function test_habit_routes_require_authentication(): void
    {
        $this->getJson('/api/habits')->assertUnauthorized();
        $this->postJson('/api/habits', [])->assertUnauthorized();
        $this->patchJson('/api/habits/1', [])->assertUnauthorized();
        $this->putJson('/api/habits/1/logs/'.self::TODAY, [])->assertUnauthorized();
        $this->deleteJson('/api/habits/1/logs/'.self::TODAY)->assertUnauthorized();
        $this->getJson('/api/habits/1/statistics?from=2026-08-01&to='.self::TODAY)->assertUnauthorized();
        $this->putJson('/api/habits/1/limit-steps', [])->assertUnauthorized();
    }

    public function test_create_and_list_numeric_habit_with_context_and_schedule(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $goal = $this->createGoal($owner);
        $this->actingAs($owner);

        $response = $this->postJson('/api/habits', $this->numericPayload([
            'routine_id' => $routine->id,
            'goal_id' => $goal->id,
        ]))->assertCreated()
            ->assertJsonPath('data.kind', 'habit')
            ->assertJsonPath('data.mode', 'numeric')
            ->assertJsonPath('data.target_value', 20)
            ->assertJsonPath('data.unit', 'pages')
            ->assertJsonPath('data.routine.id', $routine->id)
            ->assertJsonPath('data.goal.id', $goal->id)
            ->assertJsonPath('data.schedule.schedule_type', 'weekdays')
            ->assertJsonPath('data.schedule.weekdays', ['MO', 'WE', 'FR'])
            ->assertJsonPath('data.selected_day.is_scheduled', false);

        $habitId = $response->json('data.id');
        $this->assertDatabaseHas('habits', ['id' => $habitId, 'user_id' => $owner->id]);
        $this->assertDatabaseHas('recurring_rules', ['owner_type' => 'habit', 'owner_id' => $habitId]);

        $this->getJson('/api/habits?state=active&date=2026-08-14')
            ->assertOk()
            ->assertJsonPath('date', '2026-08-14')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.selected_day.is_scheduled', true)
            ->assertJsonStructure(['data' => [['statistics', 'limit_status', 'limit_steps']]]);
    }

    public function test_exact_payload_and_mode_invariants_are_atomic(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $this->postJson('/api/habits', [
            ...$this->numericPayload(),
            'kind' => 'anti_habit',
            'unexpected' => 'must fail',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['kind', 'unexpected']);

        $this->assertDatabaseCount('habits', 0);
        $this->assertDatabaseCount('recurring_rules', 0);

        $this->postJson('/api/habits', [
            'name' => 'Walk',
            'kind' => 'habit',
            'mode' => 'yes_no',
            'target_value' => 10,
            'schedule_type' => 'daily',
            'weekdays' => ['MO'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['target_value', 'weekdays']);
    }

    public function test_update_locks_identity_and_numeric_target_after_first_fact(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner, [
            'mode' => Habit::MODE_NUMERIC,
            'target_value' => 20,
            'unit' => 'pages',
        ]);
        $this->createLog($habit, $owner, self::TODAY, [
            'outcome' => HabitLog::OUTCOME_RECORDED,
            'value' => 25,
            'occurred_time' => '08:30',
        ]);
        $this->actingAs($owner);

        $this->patchJson("/api/habits/{$habit->id}", [
            'kind' => 'anti_habit',
            'mode' => 'abstinence',
            'target_value' => 30,
            'unit' => 'minutes',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['kind', 'mode', 'target_value', 'unit']);

        $this->assertSame('20.000', $habit->fresh()->target_value);
    }

    public function test_mode_aware_log_upsert_correction_and_clear_are_idempotent(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner, [
            'mode' => Habit::MODE_NUMERIC,
            'target_value' => 20,
            'unit' => 'pages',
        ]);
        $this->actingAs($owner);

        $this->putJson("/api/habits/{$habit->id}/logs/".self::TODAY, [
            'outcome' => 'recorded',
            'value' => 25,
            'occurred_time' => '08:15',
        ])->assertOk()
            ->assertJsonPath('data.selected_day.log.value', 25)
            ->assertJsonPath('data.selected_day.log.successful', true);

        $this->putJson("/api/habits/{$habit->id}/logs/".self::TODAY, [
            'outcome' => 'recorded',
            'value' => 10,
            'occurred_time' => '08:20',
        ])->assertOk()
            ->assertJsonPath('data.selected_day.log.successful', false);

        $this->assertDatabaseCount('habit_logs', 1);
        $occurrence = $this->occurrenceOn($habit);
        $this->assertNotNull($occurrence->fresh()->habit_log_id);
        $this->assertSame(PlannedOccurrence::STATUS_DONE, $occurrence->fresh()->status);

        $this->deleteJson("/api/habits/{$habit->id}/logs/".self::TODAY)->assertNoContent();
        $this->deleteJson("/api/habits/{$habit->id}/logs/".self::TODAY)->assertNoContent();
        $this->assertDatabaseCount('habit_logs', 0);
        $this->assertNull($occurrence->fresh()->habit_log_id);
        $this->assertSame(PlannedOccurrence::STATUS_PLANNED, $occurrence->fresh()->status);
    }

    public function test_log_rejects_wrong_outcome_negative_value_missing_time_and_unscheduled_date(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner, [
            'kind' => Habit::KIND_ANTI_HABIT,
            'mode' => Habit::MODE_ABSTINENCE,
        ]);
        $this->actingAs($owner);

        $this->putJson("/api/habits/{$habit->id}/logs/".self::TODAY, [
            'outcome' => 'recorded',
            'value' => -1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['outcome', 'value', 'occurred_time']);

        $this->putJson("/api/habits/{$habit->id}/logs/2026-01-01", [
            'outcome' => 'protected',
            'occurred_time' => '08:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['date']);
    }

    public function test_nested_foreign_resources_use_not_found_boundary(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $foreign = $this->createHabit($other);
        $this->actingAs($owner);

        $this->patchJson("/api/habits/{$foreign->id}", ['name' => 'Stolen'])->assertNotFound();
        $this->putJson("/api/habits/{$foreign->id}/logs/".self::TODAY, [
            'outcome' => 'done', 'occurred_time' => '08:00',
        ])->assertNotFound();
        $this->deleteJson("/api/habits/{$foreign->id}/logs/".self::TODAY)->assertNotFound();
        $this->getJson("/api/habits/{$foreign->id}/statistics?from=2026-08-01&to=".self::TODAY)
            ->assertNotFound();
        $this->putJson("/api/habits/{$foreign->id}/limit-steps", ['steps' => []])->assertNotFound();
    }

    public function test_statistics_range_and_stepped_plan_have_exact_contracts(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner, [
            'kind' => Habit::KIND_ANTI_HABIT,
            'mode' => Habit::MODE_STEPPED_LIMIT,
            'unit' => 'drinks',
        ]);
        $this->actingAs($owner);

        $this->putJson("/api/habits/{$habit->id}/limit-steps", ['steps' => [
            ['effective_on' => '2026-08-01', 'limit_value' => 1, 'period' => 'day'],
            ['effective_on' => '2026-08-10', 'limit_value' => 5, 'period' => 'week'],
        ]])->assertOk()
            ->assertJsonPath('data.limit_steps.1.status', 'current')
            ->assertJsonPath('data.limit_status.state', 'within');

        $this->getJson("/api/habits/{$habit->id}/statistics?from=2026-08-01&to=".self::TODAY)
            ->assertOk()
            ->assertJsonPath('data.from', '2026-08-01')
            ->assertJsonPath('data.to', self::TODAY);

        $this->getJson("/api/habits/{$habit->id}/statistics?from=2026-09-01&to=2026-08-01")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);
    }

    public function test_dst_gap_and_localized_domain_feedback_are_rejected_without_a_fact(): void
    {
        $owner = $this->createUser(timezone: 'Europe/Kyiv', locale: 'uk-UA');
        $habit = $this->createHabit($owner, ['starts_on' => '2026-03-29']);
        PlannedOccurrence::create([
            'user_id' => $owner->id,
            'recurring_rule_id' => $habit->recurringRule->id,
            'occurrence_date' => '2026-03-29',
            'slot' => '',
            'status' => PlannedOccurrence::STATUS_PLANNED,
            'materialized_at' => now(),
        ]);

        $response = $this->actingAs($owner)
            ->withHeader('Accept-Language', 'uk-UA')
            ->putJson("/api/habits/{$habit->id}/logs/2026-03-29", [
                'outcome' => 'done',
                'occurred_time' => '03:30',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors(['occurred_time']);

        $this->assertNotSame('The selected local time does not exist.', $response->json('errors.occurred_time.0'));
        $this->assertDatabaseCount('habit_logs', 0);
    }

    /** @param array<string, mixed> $overrides */
    private function numericPayload(array $overrides = []): array
    {
        return [
            'name' => 'Read pages',
            'description' => 'Read before bed',
            'kind' => 'habit',
            'mode' => 'numeric',
            'target_value' => 20,
            'unit' => 'pages',
            'schedule_type' => 'weekdays',
            'weekdays' => ['MO', 'WE', 'FR'],
            'preferred_time' => '21:00',
            'starts_on' => self::TODAY,
            'ends_on' => null,
            'intention_place' => 'Bedroom',
            'two_minute_starter' => 'Read one page',
            ...$overrides,
        ];
    }
}
