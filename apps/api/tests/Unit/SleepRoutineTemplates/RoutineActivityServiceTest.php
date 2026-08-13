<?php

namespace Tests\Unit\SleepRoutineTemplates;

use App\Models\PlannedOccurrence;
use App\Models\Routine;
use App\Models\RoutineActivityLog;
use App\Models\RoutineLog;
use App\Services\RoutineActivityLogService;
use App\Services\RoutineActivityService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Feature\SleepRoutineTemplates\SleepRoutineTestCase;

class RoutineActivityServiceTest extends SleepRoutineTestCase
{
    /** @return list<array<string, mixed>> */
    private function definition(): array
    {
        return [
            ['name' => 'Water', 'sort_order' => 0, 'preferred_time' => null, 'progress_total' => null],
            ['name' => 'Mobility', 'sort_order' => 1, 'preferred_time' => '07:30', 'progress_total' => null],
            ['name' => 'Read', 'sort_order' => 2, 'preferred_time' => null, 'progress_total' => 20],
        ];
    }

    public function test_activity_list_replacement_is_exact_ordered_and_atomic(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $service = app(RoutineActivityService::class);

        $accepted = $service->replace($routine, $owner, $this->definition());
        $this->assertSame(['Water', 'Mobility', 'Read'], $accepted->pluck('name')->all());
        $this->assertSame([0, 1, 2], $accepted->pluck('sort_order')->all());

        $payload = $accepted->map(fn ($activity): array => [
            'id' => $activity->id,
            'name' => $activity->name === 'Water' ? 'Hydrate' : $activity->name,
            'sort_order' => 2 - $activity->sort_order,
            'preferred_time' => $activity->preferred_time,
            'progress_total' => $activity->progress_total,
        ])->all();
        $updated = $service->replace($routine, $owner, $payload);
        $this->assertSame(['Read', 'Mobility', 'Hydrate'], $updated->pluck('name')->all());

        try {
            $service->replace($routine, $owner, [
                ['id' => $updated[0]->id, 'name' => 'One', 'sort_order' => 0],
                ['id' => $updated[1]->id, 'name' => 'Two', 'sort_order' => 0],
            ]);
            $this->fail('Duplicate order must fail.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('activities.1.sort_order', $error->errors());
        }
        $this->assertSame(['Read', 'Mobility', 'Hydrate'], $routine->activities()->orderBy('sort_order')->pluck('name')->all());
    }

    public function test_foreign_or_duplicate_ids_are_rejected_without_partial_replacement(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $routine = $this->createRoutine($owner);
        $foreignRoutine = $this->createRoutine($other);
        $service = app(RoutineActivityService::class);
        $activities = $service->replace($routine, $owner, $this->definition());
        $foreign = $this->createActivity($foreignRoutine);

        foreach ([
            [
                ['id' => $foreign->id, 'name' => 'Foreign', 'sort_order' => 0],
            ],
            [
                ['id' => $activities[0]->id, 'name' => 'First', 'sort_order' => 0],
                ['id' => $activities[0]->id, 'name' => 'Duplicate', 'sort_order' => 1],
            ],
        ] as $payload) {
            try {
                $service->replace($routine, $owner, $payload);
                $this->fail('Foreign/duplicate id must fail.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(3, $routine->activities()->count());
    }

    public function test_first_activity_fact_locks_membership_and_totals_but_allows_cosmetic_edits(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $service = app(RoutineActivityService::class);
        $activities = $service->replace($routine, $owner, $this->definition());
        app(RoutineActivityLogService::class)->upsert(
            $routine,
            $activities[0],
            $owner,
            self::TODAY,
            ['status' => RoutineActivityLog::STATUS_DONE, 'progress_value' => null, 'note' => null],
        );

        foreach ([
            $activities->slice(0, 2)->map(fn ($activity): array => [
                'id' => $activity->id,
                'name' => $activity->name,
                'sort_order' => $activity->sort_order,
                'preferred_time' => $activity->preferred_time,
                'progress_total' => $activity->progress_total,
            ])->all(),
            $activities->map(fn ($activity): array => [
                'id' => $activity->id,
                'name' => $activity->name,
                'sort_order' => $activity->sort_order,
                'preferred_time' => $activity->preferred_time,
                'progress_total' => $activity->name === 'Read' ? 30 : $activity->progress_total,
            ])->all(),
        ] as $invalid) {
            try {
                $service->replace($routine, $owner, $invalid);
                $this->fail('Semantic edits after facts must fail.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('activities', $error->errors());
            }
        }

        $cosmetic = $activities->map(fn ($activity): array => [
            'id' => $activity->id,
            'name' => $activity->name.' updated',
            'sort_order' => 2 - $activity->sort_order,
            'preferred_time' => $activity->name === 'Water' ? '07:00' : $activity->preferred_time,
            'progress_total' => $activity->progress_total,
        ])->all();
        $updated = $service->replace($routine, $owner, $cosmetic);
        $this->assertSame('Read updated', $updated[0]->name);
        $this->assertSame(3, $updated->count());
    }

    public function test_child_facts_drive_pending_all_done_mixed_and_reopen_parent_states(): void
    {
        CarbonImmutable::setTestNow(self::TODAY.' 09:00:00 UTC');
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $activities = app(RoutineActivityService::class)->replace($routine, $owner, $this->definition());
        $facts = app(RoutineActivityLogService::class);

        $first = $facts->upsert($routine, $activities[0], $owner, self::TODAY, [
            'status' => RoutineActivityLog::STATUS_DONE,
            'note' => 'First',
        ]);
        $this->assertSame('2026-08-13 09:00:00', $first->completed_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNull(RoutineLog::query()->where('routine_id', $routine->id)->first());
        $this->assertSame(PlannedOccurrence::STATUS_PLANNED, $this->routineOccurrenceOn($routine)->status);

        $facts->upsert($routine, $activities[1], $owner, self::TODAY, ['status' => 'done']);
        $facts->upsert($routine, $activities[2], $owner, self::TODAY, [
            'status' => 'done',
            'progress_value' => 15,
        ]);
        $parent = RoutineLog::query()->where('routine_id', $routine->id)->firstOrFail();
        $this->assertSame(RoutineLog::STATUS_DONE, $parent->status);
        $this->assertSame($parent->id, $this->routineOccurrenceOn($routine)->routine_log_id);

        CarbonImmutable::setTestNow(self::TODAY.' 10:00:00 UTC');
        $corrected = $facts->upsert($routine, $activities[0], $owner, self::TODAY, ['status' => 'skipped']);
        $this->assertNull($corrected->completed_at);
        $this->assertSame(RoutineLog::STATUS_SKIPPED, $parent->fresh()->status);
        $this->assertSame(PlannedOccurrence::STATUS_SKIPPED, $this->routineOccurrenceOn($routine)->status);

        $facts->clear($routine, $activities[1], $owner, self::TODAY);
        $this->assertDatabaseMissing('routine_logs', ['routine_id' => $routine->id, 'log_date' => self::TODAY]);
        $this->assertSame(PlannedOccurrence::STATUS_PLANNED, $this->routineOccurrenceOn($routine)->status);
    }

    public function test_progress_compatibility_and_stable_completion_time_are_enforced(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $activities = app(RoutineActivityService::class)->replace($routine, $owner, $this->definition());
        $service = app(RoutineActivityLogService::class);

        foreach ([
            [$activities[0], ['status' => 'done', 'progress_value' => 1]],
            [$activities[2], ['status' => 'done', 'progress_value' => 21]],
            [$activities[2], ['status' => 'skipped', 'progress_value' => 1]],
        ] as [$activity, $payload]) {
            try {
                $service->upsert($routine, $activity, $owner, self::TODAY, $payload);
                $this->fail('Incompatible progress must fail.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('progress_value', $error->errors());
            }
        }

        $created = $service->upsert($routine, $activities[2], $owner, self::TODAY, [
            'status' => 'done', 'progress_value' => 10,
        ]);
        CarbonImmutable::setTestNow(self::TODAY.' 11:00:00 UTC');
        $corrected = $service->upsert($routine, $activities[2], $owner, self::TODAY, [
            'status' => 'done', 'progress_value' => 12,
        ]);
        $this->assertSame($created->id, $corrected->id);
        $this->assertTrue($created->completed_at->equalTo($corrected->completed_at));
        $this->assertSame('12.000', $corrected->progress_value);
    }

    public function test_direct_rich_parent_set_is_rejected_whole_clear_and_planner_skip_use_children(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $activities = app(RoutineActivityService::class)->replace($routine, $owner, $this->definition());
        $this->actingAs($owner);

        $this->putJson("/api/routines/{$routine->id}/logs/".self::TODAY, ['status' => 'done'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        app(RoutineActivityLogService::class)->upsert($routine, $activities[0], $owner, self::TODAY, ['status' => 'done']);
        $this->putJson('/api/planner/occurrences/'.$this->routineOccurrenceOn($routine)->id.'/skip')->assertOk();
        $this->assertSame(3, RoutineActivityLog::query()->where('log_date', self::TODAY)->count());
        $this->assertSame(2, RoutineActivityLog::query()->where('status', 'skipped')->count());
        $this->assertDatabaseHas('routine_logs', ['routine_id' => $routine->id, 'status' => 'skipped']);

        $this->deleteJson("/api/routines/{$routine->id}/logs/".self::TODAY)->assertNoContent();
        $this->assertDatabaseCount('routine_activity_logs', 0);
        $this->assertDatabaseMissing('routine_logs', ['routine_id' => $routine->id, 'log_date' => self::TODAY]);
    }

    public function test_zero_activity_routine_keeps_legacy_direct_completion(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner, ['day_period' => Routine::DAY_PERIOD_ANYTIME]);
        $this->actingAs($owner);

        $this->putJson("/api/routines/{$routine->id}/logs/".self::TODAY, ['status' => 'done'])
            ->assertOk()
            ->assertJsonPath('data.status', 'done');
        $this->deleteJson("/api/routines/{$routine->id}/logs/".self::TODAY)->assertNoContent();
        $this->assertDatabaseCount('routine_logs', 0);
    }
}
