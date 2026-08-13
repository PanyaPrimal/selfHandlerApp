<?php

namespace Tests\Feature\SleepRoutineTemplates;

use App\Models\Routine;
use App\Models\RoutineActivityLog;
use App\Models\RoutineDaySelection;

class SleepRoutineApiTest extends SleepRoutineTestCase
{
    /** @return array<string, mixed> */
    private function planPayload(array $overrides = []): array
    {
        return [
            'name' => 'Regular night',
            'planned_bed_time' => '23:00',
            'planned_wake_time' => '07:00',
            'schedule_type' => 'daily',
            'starts_on' => self::TODAY,
            'ends_on' => null,
            'is_active' => true,
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    private function logPayload(array $overrides = []): array
    {
        return [
            'actual_bed_date' => self::TODAY,
            'actual_bed_time' => '23:15',
            'actual_wake_date' => self::TOMORROW,
            'actual_wake_time' => '07:15',
            'quality' => 8,
            'note' => 'Rested',
            ...$overrides,
        ];
    }

    public function test_all_ten_new_operations_require_authentication(): void
    {
        foreach ([
            ['getJson', '/api/sleep'],
            ['postJson', '/api/sleep/plans', $this->planPayload()],
            ['patchJson', '/api/sleep/plans/1', ['name' => 'Changed']],
            ['putJson', '/api/sleep/plans/1/logs/'.self::TODAY, $this->logPayload()],
            ['deleteJson', '/api/sleep/plans/1/logs/'.self::TODAY],
            ['getJson', '/api/sleep/statistics?from='.self::TODAY.'&to='.self::TODAY],
            ['putJson', '/api/routines/1/activities', ['activities' => []]],
            ['putJson', '/api/routines/1/activities/1/logs/'.self::TODAY, ['status' => 'done']],
            ['deleteJson', '/api/routines/1/activities/1/logs/'.self::TODAY],
            ['putJson', '/api/routine-selections/'.self::TODAY, [
                'morning_routine_id' => null, 'evening_routine_id' => null,
            ]],
        ] as $case) {
            [$method, $uri] = $case;
            $payload = $case[2] ?? null;
            $response = isset($payload) ? $this->{$method}($uri, $payload) : $this->{$method}($uri);
            $response->assertUnauthorized();
        }
    }

    public function test_sleep_plan_create_list_update_and_lifecycle_are_strict_and_idempotent(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $created = $this->postJson('/api/sleep/plans', $this->planPayload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Regular night')
            ->assertJsonPath('data.planned_wake_time', '07:00')
            ->assertJsonPath('data.schedule.planned_bed_time', '23:00')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_archived', false)
            ->assertJsonStructure(['data' => [
                'id', 'name', 'planned_wake_time', 'is_active', 'is_archived', 'archived_at',
                'schedule' => ['schedule_type', 'weekdays', 'planned_bed_time', 'starts_on', 'ends_on'],
                'selected_night',
            ]]);
        $planId = $created->json('data.id');

        $this->postJson('/api/sleep/plans', $this->planPayload(['name' => 'Second active']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');
        $this->assertDatabaseCount('sleep_plans', 1);

        $this->patchJson("/api/sleep/plans/{$planId}", [
            'name' => 'Edited',
            'planned_bed_time' => '22:30',
            'planned_wake_time' => '06:45',
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Edited')
            ->assertJsonPath('data.schedule.planned_bed_time', '22:30')
            ->assertJsonPath('data.is_active', false);

        $this->patchJson("/api/sleep/plans/{$planId}", ['is_archived' => true])
            ->assertOk()
            ->assertJsonPath('data.is_archived', true)
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.archived_at', '2026-08-13T09:00:00.000000Z');
        $this->patchJson("/api/sleep/plans/{$planId}", ['is_archived' => true])
            ->assertOk()
            ->assertJsonPath('data.archived_at', '2026-08-13T09:00:00.000000Z');
        $this->patchJson("/api/sleep/plans/{$planId}", ['is_archived' => false, 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_archived', false)
            ->assertJsonPath('data.is_active', false);

        $this->getJson('/api/sleep?state=paused&date='.self::TODAY)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $planId)
            ->assertJsonPath('date', self::TODAY);

        $this->patchJson("/api/sleep/plans/{$planId}", ['future_field' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request');
        $this->patchJson("/api/sleep/plans/{$planId}", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request');
    }

    public function test_sleep_validation_and_log_statistics_contracts_are_exact(): void
    {
        $owner = $this->createUser(timezone: 'Europe/Kyiv');
        $this->actingAs($owner);

        $this->postJson('/api/sleep/plans', $this->planPayload([
            'planned_wake_time' => '23:00',
        ]))->assertUnprocessable()->assertJsonValidationErrors('planned_wake_time');
        $this->postJson('/api/sleep/plans', $this->planPayload([
            'schedule_type' => 'weekdays', 'weekdays' => [],
        ]))->assertUnprocessable()->assertJsonValidationErrors('weekdays');
        $this->postJson('/api/sleep/plans', $this->planPayload([
            'unknown' => 'rejected',
        ]))->assertUnprocessable()->assertJsonValidationErrors('request');

        $planId = $this->postJson('/api/sleep/plans', $this->planPayload())
            ->assertCreated()->json('data.id');
        $logId = $this->putJson("/api/sleep/plans/{$planId}/logs/".self::TODAY, $this->logPayload())
            ->assertOk()
            ->assertJsonPath('data.selected_night.state', 'recorded')
            ->assertJsonPath('data.selected_night.log.duration_minutes', 480)
            ->assertJsonPath('data.selected_night.log.actual_bed_at', '2026-08-13T20:15:00.000000Z')
            ->json('data.selected_night.log.id');

        $secondId = $this->putJson("/api/sleep/plans/{$planId}/logs/".self::TODAY, $this->logPayload([
            'quality' => 6, 'actual_wake_time' => '06:45', 'note' => null,
        ]))->assertOk()
            ->assertJsonPath('data.selected_night.log.quality', 6)
            ->assertJsonPath('data.selected_night.log.note', null)
            ->json('data.selected_night.log.id');
        $this->assertSame($logId, $secondId);

        $this->getJson('/api/sleep/statistics?from='.self::TODAY.'&to='.self::TODAY)
            ->assertOk()
            ->assertJsonPath('data.planned_nights', 1)
            ->assertJsonPath('data.recorded_nights', 1)
            ->assertJsonPath('data.average_quality', 6);

        $this->putJson("/api/sleep/plans/{$planId}/logs/".self::TODAY, $this->logPayload([
            'unknown' => true,
        ]))->assertUnprocessable()->assertJsonValidationErrors('request');
        $this->deleteJson("/api/sleep/plans/{$planId}/logs/".self::TODAY)->assertNoContent();
        $this->deleteJson("/api/sleep/plans/{$planId}/logs/".self::TODAY)->assertNoContent();
        $this->assertDatabaseCount('sleep_logs', 0);
    }

    public function test_routine_day_period_activity_and_fact_endpoints_are_strict(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);
        $routineId = $this->postJson('/api/routines', [
            'name' => 'Morning reset',
            'day_period' => 'morning',
            'schedule_type' => 'daily',
            'preferred_time' => '07:00',
            'starts_on' => self::TODAY,
        ])->assertCreated()
            ->assertJsonPath('data.day_period', 'morning')
            ->assertJsonPath('data.activities', [])
            ->json('data.id');

        $activities = $this->putJson("/api/routines/{$routineId}/activities", [
            'activities' => [
                ['name' => 'Water', 'sort_order' => 0, 'preferred_time' => null, 'progress_total' => null],
                ['name' => 'Read', 'sort_order' => 1, 'preferred_time' => '07:30', 'progress_total' => 20],
            ],
        ])->assertOk()
            ->assertJsonPath('data.day_period', 'morning')
            ->assertJsonCount(2, 'data.activities')
            ->json('data.activities');

        $activityId = $activities[1]['id'];
        $this->putJson("/api/routines/{$routineId}/activities/{$activityId}/logs/".self::TODAY, [
            'status' => 'done', 'progress_value' => 12.5, 'note' => 'Pages',
        ])->assertOk()
            ->assertJsonPath('data.parent_state', 'pending')
            ->assertJsonPath('data.activities.1.selected_day_log.progress_value', 12.5);

        $this->putJson("/api/routines/{$routineId}/activities/{$activityId}/logs/".self::TODAY, [
            'status' => 'skipped', 'progress_value' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('progress_value');
        $this->deleteJson("/api/routines/{$routineId}/activities/{$activityId}/logs/".self::TODAY)
            ->assertOk()
            ->assertJsonPath('data.parent_state', 'pending');

        $this->patchJson("/api/routines/{$routineId}", ['day_period' => 'night'])
            ->assertUnprocessable()->assertJsonValidationErrors('day_period');
        $this->putJson("/api/routines/{$routineId}/activities", [
            'activities' => [['name' => 'Bad', 'sort_order' => 0, 'extra' => true]],
        ])->assertUnprocessable()->assertJsonValidationErrors('request');
    }

    public function test_day_selection_replaces_both_slots_and_enforces_owner_boundaries(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $morning = $this->createRoutine($owner);
        $evening = $this->createRoutine($owner, ['day_period' => Routine::DAY_PERIOD_EVENING]);
        $foreign = $this->createRoutine($other);
        $this->actingAs($owner);

        $this->putJson('/api/routine-selections/'.self::TODAY, [
            'morning_routine_id' => $morning->id,
            'evening_routine_id' => $evening->id,
        ])->assertOk()
            ->assertJsonPath('data.morning.selected.routine_id', $morning->id)
            ->assertJsonPath('data.evening.selected.routine_id', $evening->id);

        $this->putJson('/api/routine-selections/'.self::TODAY, [
            'morning_routine_id' => null,
            'evening_routine_id' => $evening->id,
        ])->assertOk()->assertJsonPath('data.morning.selected', null);

        $this->putJson('/api/routine-selections/'.self::TODAY, [
            'morning_routine_id' => $foreign->id,
            'evening_routine_id' => $evening->id,
        ])->assertNotFound();
        $this->putJson('/api/routine-selections/'.self::TODAY, [
            'morning_routine_id' => null,
        ])->assertUnprocessable()->assertJsonValidationErrors('evening_routine_id');
        $this->putJson('/api/routine-selections/'.self::TODAY, [
            'morning_routine_id' => null,
            'evening_routine_id' => $evening->id,
            'unknown' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('request');

        $this->assertSame(2, RoutineDaySelection::query()->ownedBy($owner)->count());
    }

    public function test_foreign_nested_resources_are_hidden_and_never_mutated(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $plan = $this->createSleepPlan($other);
        $routine = $this->createRoutine($other);
        $activity = $this->createActivity($routine);
        $this->actingAs($owner);

        $this->patchJson("/api/sleep/plans/{$plan->id}", ['name' => 'Stolen'])->assertNotFound();
        $this->putJson("/api/sleep/plans/{$plan->id}/logs/".self::TODAY, $this->logPayload())->assertNotFound();
        $this->putJson("/api/routines/{$routine->id}/activities", ['activities' => []])->assertNotFound();
        $this->putJson("/api/routines/{$routine->id}/activities/{$activity->id}/logs/".self::TODAY, [
            'status' => RoutineActivityLog::STATUS_DONE,
        ])->assertNotFound();

        $this->assertSame('Regular night', $plan->fresh()->name);
        $this->assertDatabaseCount('sleep_logs', 0);
        $this->assertDatabaseCount('routine_activity_logs', 0);
    }
}
