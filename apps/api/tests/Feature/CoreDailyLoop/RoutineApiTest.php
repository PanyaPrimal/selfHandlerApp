<?php

namespace Tests\Feature\CoreDailyLoop;

use Carbon\CarbonImmutable;

class RoutineApiTest extends CoreDailyLoopTestCase
{
    private const MONDAY = '2026-08-10';

    public function test_routine_creation_requires_a_valid_explicit_schedule(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $this->postJson('/api/routines', ['name' => 'Missing schedule'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('schedule_type');

        $this->postJson('/api/routines', [
            'name' => 'Empty weekday schedule',
            'schedule_type' => 'weekdays',
            'weekdays' => [],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('weekdays');

        $this->postJson('/api/routines', [
            'name' => 'Duplicate weekday schedule',
            'schedule_type' => 'weekdays',
            'weekdays' => ['MO', 'MO'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('weekdays.1');

        $this->postJson('/api/routines', [
            'name' => 'Backwards validity window',
            'schedule_type' => 'daily',
            'starts_on' => '2026-08-11',
            'ends_on' => self::MONDAY,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('ends_on');

        $response = $this->postJson('/api/routines', [
            'name' => '  Morning stretch  ',
            'description' => '  Start gently  ',
            'schedule_type' => 'weekdays',
            'weekdays' => ['WE', 'MO'],
            'sort_order' => 3,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Morning stretch')
            ->assertJsonPath('data.description', 'Start gently')
            ->assertJsonPath('data.schedule_type', 'weekdays')
            ->assertJsonPath('data.weekdays', ['MO', 'WE'])
            ->assertJsonPath('data.sort_order', 3)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_archived', false);

        $this->assertDatabaseHas('routines', [
            'id' => $response->json('data.id'),
            'user_id' => $owner->id,
            'name' => 'Morning stretch',
        ]);
        $this->assertDatabaseCount('routine_weekdays', 2);
    }

    public function test_routine_fields_respect_the_contract_limits(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $this->postJson('/api/routines', [
            'name' => str_repeat('n', 161),
            'description' => str_repeat('d', 2001),
            'schedule_type' => 'daily',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'description']);
    }

    public function test_empty_routine_update_is_rejected(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $this->actingAs($owner);

        $this->patchJson("/api/routines/{$routine->id}", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request');
    }

    public function test_routine_update_with_only_an_unknown_field_is_rejected(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $this->actingAs($owner);

        $this->patchJson("/api/routines/{$routine->id}", [
            'future_field' => 'ignored',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('request');

        $this->assertSame('Morning walk', $routine->fresh()->name);
    }

    public function test_partial_updates_validate_against_the_stored_validity_window(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner, [
            'starts_on' => '2026-08-10',
            'ends_on' => '2026-08-20',
        ]);
        $this->actingAs($owner);

        $this->patchJson("/api/routines/{$routine->id}", ['ends_on' => '2026-08-09'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_on');

        $this->patchJson("/api/routines/{$routine->id}", ['starts_on' => '2026-08-21'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_on');

        $this->assertDatabaseHas('routines', [
            'id' => $routine->id,
            'starts_on' => '2026-08-10',
            'ends_on' => '2026-08-20',
        ]);
    }

    public function test_routines_can_be_edited_paused_archived_restored_and_listed_by_archive_state(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');

        try {
            $owner = $this->createUser();
            $current = $this->createRoutine($owner, ['name' => 'Current routine']);
            $archived = $this->createRoutine($owner, ['name' => 'Archive me']);
            $this->actingAs($owner);

            $this->patchJson("/api/routines/{$current->id}", [
                'name' => 'Edited routine',
                'description' => 'Updated description',
                'is_active' => false,
                'ends_on' => '2026-08-31',
            ])->assertOk()
                ->assertJsonPath('data.name', 'Edited routine')
                ->assertJsonPath('data.is_active', false)
                ->assertJsonPath('data.ends_on', '2026-08-31');

            $this->patchJson("/api/routines/{$archived->id}", ['is_archived' => true])
                ->assertOk()
                ->assertJsonPath('data.is_archived', true)
                ->assertJsonPath('data.archived_at', '2026-08-10T12:00:00.000000Z');

            $this->getJson('/api/routines')
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $current->id);

            $this->getJson('/api/routines?archived=true')
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $archived->id);

            $this->patchJson("/api/routines/{$archived->id}", ['is_archived' => false])
                ->assertOk()
                ->assertJsonPath('data.is_archived', false)
                ->assertJsonPath('data.archived_at', null);

            $this->assertDatabaseHas('routines', [
                'id' => $archived->id,
                'is_archived' => false,
                'archived_at' => null,
            ]);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_schedule_defining_fields_are_immutable_after_the_first_log(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner, [
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-31',
        ], ['MO', 'WE']);
        $this->createLog($routine, self::MONDAY);
        $this->actingAs($owner);

        $this->patchJson("/api/routines/{$routine->id}", [
            'name' => 'Must remain unchanged on failure',
            'schedule_type' => 'daily',
            'weekdays' => ['FR'],
            'starts_on' => '2026-08-02',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['schedule_type', 'weekdays', 'starts_on']);

        $routine->refresh();
        $this->assertSame('Morning walk', $routine->name);
        $this->assertSame('weekdays', $routine->schedule_type);
        $this->assertSame(['MO', 'WE'], $routine->weekdays);
        $this->assertSame('2026-08-01', $routine->starts_on->format('Y-m-d'));

        $this->patchJson("/api/routines/{$routine->id}", [
            'name' => 'History-safe edit',
            'ends_on' => '2026-09-30',
        ])->assertOk()
            ->assertJsonPath('data.name', 'History-safe edit')
            ->assertJsonPath('data.ends_on', '2026-09-30');
    }

    public function test_archive_round_trip_preserves_pause_state_and_the_original_archive_time(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');

        try {
            $owner = $this->createUser();
            $routine = $this->createRoutine($owner, ['is_active' => false]);
            $this->actingAs($owner);

            $this->patchJson("/api/routines/{$routine->id}", ['is_archived' => true])
                ->assertOk()
                ->assertJsonPath('data.is_active', false)
                ->assertJsonPath('data.archived_at', '2026-08-10T12:00:00.000000Z');

            CarbonImmutable::setTestNow('2026-08-11 12:00:00 UTC');

            $this->patchJson("/api/routines/{$routine->id}", ['is_archived' => true])
                ->assertOk()
                ->assertJsonPath('data.archived_at', '2026-08-10T12:00:00.000000Z');

            $this->patchJson("/api/routines/{$routine->id}", ['is_archived' => false])
                ->assertOk()
                ->assertJsonPath('data.is_active', false)
                ->assertJsonPath('data.archived_at', null);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_schedule_can_change_before_history_and_daily_schedules_store_no_weekday_rows(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $this->actingAs($owner);

        $this->patchJson("/api/routines/{$routine->id}", [
            'schedule_type' => 'weekdays',
            'weekdays' => ['FR', 'MO'],
            'starts_on' => '2026-08-01',
        ])->assertOk()
            ->assertJsonPath('data.schedule_type', 'weekdays')
            ->assertJsonPath('data.weekdays', ['MO', 'FR']);

        $this->assertDatabaseCount('routine_weekdays', 2);

        $this->patchJson("/api/routines/{$routine->id}", ['schedule_type' => 'daily'])
            ->assertOk()
            ->assertJsonPath('data.schedule_type', 'daily')
            ->assertJsonPath('data.weekdays', []);

        $this->assertDatabaseCount('routine_weekdays', 0);
    }

    public function test_today_contains_only_scheduled_routines_in_stable_order_and_matches_its_contract(): void
    {
        $owner = $this->createUser();
        $sameNameFirst = $this->createRoutine($owner, ['name' => 'Alpha', 'sort_order' => 0]);
        $sameNameSecond = $this->createRoutine($owner, ['name' => 'Alpha', 'sort_order' => 0]);
        $weekday = $this->createRoutine($owner, ['name' => 'Monday only', 'sort_order' => 1], ['MO']);
        $this->createRoutine($owner, ['name' => 'Tuesday only'], ['TU']);
        $this->createRoutine($owner, ['name' => 'Not started', 'starts_on' => '2026-08-11']);
        $this->createRoutine($owner, ['name' => 'Already ended', 'ends_on' => '2026-08-09']);
        $this->createRoutine($owner, ['name' => 'Paused', 'is_active' => false]);
        $this->createRoutine($owner, [
            'name' => 'Archived',
            'is_archived' => true,
            'archived_at' => '2026-08-10 00:00:00 UTC',
        ]);
        $this->actingAs($owner);

        $this->getJson('/api/today?date='.self::MONDAY)
            ->assertOk()
            ->assertJsonPath('date', self::MONDAY)
            ->assertJsonPath('summary', [
                'scheduled' => 3,
                'done' => 0,
                'skipped' => 0,
                'pending' => 3,
                'completion_rate' => 0,
            ])
            ->assertJsonPath('routines.0.id', $sameNameFirst->id)
            ->assertJsonPath('routines.1.id', $sameNameSecond->id)
            ->assertJsonPath('routines.2.id', $weekday->id)
            ->assertJsonStructure([
                'date',
                'summary' => ['scheduled', 'done', 'skipped', 'pending', 'completion_rate'],
                'routines' => [[
                    'id', 'name', 'description', 'kind', 'preferred_time', 'sort_order',
                    'is_active', 'is_archived', 'log', 'goals',
                ]],
                'goals',
                'review',
            ]);
    }

    public function test_today_defaults_to_the_calendar_day_in_the_configured_timezone(): void
    {
        CarbonImmutable::setTestNow('2026-08-09 21:30:00 UTC');

        try {
            config(['selfhandler.timezone' => 'Europe/Kyiv']);
            $owner = $this->createUser();
            $mondayRoutine = $this->createRoutine($owner, [], ['MO']);
            $this->actingAs($owner);

            $this->getJson('/api/today')
                ->assertOk()
                ->assertJsonPath('date', self::MONDAY)
                ->assertJsonPath('routines.0.id', $mondayRoutine->id);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_done_skipped_and_pending_transitions_are_idempotent_and_recalculate_today(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 08:00:00 UTC');

        try {
            $owner = $this->createUser();
            $routine = $this->createRoutine($owner);
            $this->actingAs($owner);

            $this->getJson('/api/today?date='.self::MONDAY)
                ->assertJsonPath('summary.pending', 1)
                ->assertJsonPath('routines.0.log', null);

            $first = $this->putJson("/api/routines/{$routine->id}/logs/".self::MONDAY, [
                'status' => 'done',
                'note' => 'Finished',
            ])->assertOk()
                ->assertJsonPath('data.status', 'done')
                ->assertJsonPath('data.note', 'Finished')
                ->assertJsonPath('data.completed_at', '2026-08-10T08:00:00.000000Z')
                ->json('data.id');

            $second = $this->putJson("/api/routines/{$routine->id}/logs/".self::MONDAY, [
                'status' => 'done',
                'note' => 'Finished',
            ])->assertOk()->json('data.id');

            $this->assertSame($first, $second);
            $this->assertDatabaseCount('routine_logs', 1);

            $this->getJson('/api/today?date='.self::MONDAY)
                ->assertJsonPath('summary.done', 1)
                ->assertJsonPath('summary.pending', 0)
                ->assertJsonPath('summary.completion_rate', 100);

            $this->putJson("/api/routines/{$routine->id}/logs/".self::MONDAY, ['status' => 'skipped'])
                ->assertOk()
                ->assertJsonPath('data.status', 'skipped')
                ->assertJsonPath('data.completed_at', null);

            $this->assertDatabaseCount('routine_logs', 1);
            $this->getJson('/api/today?date='.self::MONDAY)
                ->assertJsonPath('summary.done', 0)
                ->assertJsonPath('summary.skipped', 1)
                ->assertJsonPath('summary.pending', 0)
                ->assertJsonPath('summary.completion_rate', 0);

            $this->deleteJson("/api/routines/{$routine->id}/logs/".self::MONDAY)->assertNoContent();
            $this->deleteJson("/api/routines/{$routine->id}/logs/".self::MONDAY)->assertNoContent();

            $this->assertDatabaseCount('routine_logs', 0);
            $this->getJson('/api/today?date='.self::MONDAY)
                ->assertJsonPath('summary.pending', 1)
                ->assertJsonPath('routines.0.log', null);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_log_status_and_note_limits_are_validated_without_mutation(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $this->actingAs($owner);

        $this->putJson("/api/routines/{$routine->id}/logs/".self::MONDAY, ['status' => 'pending'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->putJson("/api/routines/{$routine->id}/logs/".self::MONDAY, [
            'status' => 'done',
            'note' => str_repeat('n', 2001),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('note');

        $this->assertDatabaseCount('routine_logs', 0);
    }

    public function test_archiving_hides_current_planning_but_keeps_a_logged_historical_occurrence(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 12:00:00 UTC');

        try {
            config(['selfhandler.timezone' => 'Europe/Kyiv']);
            $owner = $this->createUser();
            $routine = $this->createRoutine($owner);
            $this->createLog($routine, '2026-08-09');
            $this->createLog($routine, self::MONDAY);
            $this->actingAs($owner);

            $this->patchJson("/api/routines/{$routine->id}", ['is_archived' => true])->assertOk();

            $this->getJson('/api/today?date=2026-08-09')
                ->assertOk()
                ->assertJsonPath('summary.scheduled', 1)
                ->assertJsonPath('summary.done', 1)
                ->assertJsonPath('routines.0.id', $routine->id);

            $this->getJson('/api/today?date='.self::MONDAY)
                ->assertOk()
                ->assertJsonPath('summary.scheduled', 0)
                ->assertJsonCount(0, 'routines');

            $this->assertDatabaseCount('routine_logs', 2);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_today_and_log_paths_reject_invalid_calendar_dates(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $this->actingAs($owner);

        $this->getJson('/api/today?date=not-a-date')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');

        $this->putJson("/api/routines/{$routine->id}/logs/not-a-date", ['status' => 'done'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    public function test_another_owner_cannot_clear_a_routine_log(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test', 'Other Owner');
        $routine = $this->createRoutine($other);
        $this->createLog($routine, self::MONDAY);
        $this->actingAs($owner);

        $this->deleteJson("/api/routines/{$routine->id}/logs/".self::MONDAY)->assertNotFound();

        $this->assertDatabaseHas('routine_logs', [
            'routine_id' => $routine->id,
            'log_date' => self::MONDAY,
            'status' => 'done',
        ]);
    }
}
