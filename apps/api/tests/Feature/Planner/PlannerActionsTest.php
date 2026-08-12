<?php

namespace Tests\Feature\Planner;

use App\Models\PlannedOccurrence;
use App\Models\RoutineLog;
use App\Services\RecurrenceMaterializer;
use App\Services\RoutineProgressService;
use Illuminate\Support\Facades\DB;

class PlannerActionsTest extends PlannerTestCase
{
    /* ---------------------------------------------------------------- */
    /* Reschedule */
    /* ---------------------------------------------------------------- */

    public function test_a_day_moves_to_another_date_and_back(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner, 'Morning walk');
        $occurrence = $this->occurrenceOn($routine, self::TODAY);
        $this->actingAs($owner);

        $this->patchJson("/api/planner/occurrences/{$occurrence->id}/reschedule", [
            'rescheduled_to' => '2026-08-20',
        ])->assertOk();

        // Gone from today, present on the new day.
        $today = array_column($this->getJson('/api/planner/day')->json('entries'), 'title');
        $this->assertNotContains('Morning walk', $today);

        $moved = $this->getJson('/api/planner/day?date=2026-08-20')->json('entries');
        $this->assertContains('Morning walk', array_column($moved, 'title'));

        // The expanded date is untouched, so what was originally planned survives.
        $stored = $occurrence->fresh();
        $this->assertSame(self::TODAY, $stored->occurrence_date->format('Y-m-d'));
        $this->assertSame('2026-08-20', $stored->rescheduled_to->format('Y-m-d'));

        $this->patchJson("/api/planner/occurrences/{$occurrence->id}/reschedule", [
            'rescheduled_to' => null,
        ])->assertOk();

        $this->assertNull($occurrence->fresh()->rescheduled_to);
        $backToday = array_column($this->getJson('/api/planner/day')->json('entries'), 'title');
        $this->assertContains('Morning walk', $backToday);
    }

    public function test_moving_is_refused_when_it_would_misstate_what_happened(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $occurrence = $this->occurrenceOn($routine, self::TODAY);
        $this->actingAs($owner);

        // Into the past.
        $this->patchJson("/api/planner/occurrences/{$occurrence->id}/reschedule", [
            'rescheduled_to' => '2026-08-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('rescheduled_to');

        // Beyond the materialized window.
        $this->patchJson("/api/planner/occurrences/{$occurrence->id}/reschedule", [
            'rescheduled_to' => '2027-06-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('rescheduled_to');

        $this->assertNull($occurrence->fresh()->rescheduled_to);

        // Already done: moving it would claim the completion happened elsewhere.
        $this->putJson("/api/routines/{$routine->id}/logs/".self::TODAY, ['status' => 'done'])->assertOk();

        $this->patchJson("/api/planner/occurrences/{$occurrence->id}/reschedule", [
            'rescheduled_to' => self::TOMORROW,
        ])->assertUnprocessable()->assertJsonValidationErrors('rescheduled_to');

        $this->assertNull($occurrence->fresh()->rescheduled_to);
    }

    public function test_a_moved_day_survives_materialization(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $occurrence = $this->occurrenceOn($routine, self::TODAY);
        $this->actingAs($owner);

        $this->patchJson("/api/planner/occurrences/{$occurrence->id}/reschedule", [
            'rescheduled_to' => '2026-08-20',
        ])->assertOk();

        $before = PlannedOccurrence::query()->count();

        app(RecurrenceMaterializer::class)->materialize($routine->recurringRule->fresh(), self::TODAY);

        // The expanded date was never overwritten, so the run finds no gap to
        // fill and produces no duplicate.
        $this->assertSame($before, PlannedOccurrence::query()->count());
        $this->assertSame('2026-08-20', $occurrence->fresh()->rescheduled_to->format('Y-m-d'));
    }

    public function test_another_account_cannot_move_a_day(): void
    {
        $owner = $this->createUser('owner@example.test');
        $other = $this->createUser('other@example.test');
        $routine = $this->createRoutine($owner);
        $occurrence = $this->occurrenceOn($routine, self::TODAY);

        $this->actingAs($other);

        $this->patchJson("/api/planner/occurrences/{$occurrence->id}/reschedule", [
            'rescheduled_to' => self::TOMORROW,
        ])->assertNotFound();

        $this->putJson("/api/planner/occurrences/{$occurrence->id}/skip")->assertNotFound();
        $this->assertNull($occurrence->fresh()->rescheduled_to);
    }

    /* ---------------------------------------------------------------- */
    /* Skip */
    /* ---------------------------------------------------------------- */

    public function test_skipping_writes_the_same_log_today_would_write(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $occurrence = $this->occurrenceOn($routine, self::TODAY);
        $this->actingAs($owner);

        $this->putJson("/api/planner/occurrences/{$occurrence->id}/skip")->assertOk();

        $fromPlanner = RoutineLog::query()->ownedBy($owner)->firstOrFail();

        $this->assertSame('skipped', $fromPlanner->status);
        $this->assertSame(self::TODAY, $fromPlanner->log_date->format('Y-m-d'));
        $this->assertSame($routine->id, $fromPlanner->routine_id);
        $this->assertNull($fromPlanner->completed_at);

        // Exactly one log: no parallel planner-side skip state exists.
        $this->assertSame(1, RoutineLog::query()->count());

        // And the engine mirror follows, as it does for a Today skip.
        $this->assertSame('skipped', $occurrence->fresh()->status);
    }

    public function test_a_skip_from_planner_is_identical_to_a_skip_from_today(): void
    {
        $owner = $this->createUser();
        $viaPlanner = $this->createRoutine($owner, 'Via planner');
        $viaToday = $this->createRoutine($owner, 'Via today');
        $this->actingAs($owner);

        $this->putJson('/api/planner/occurrences/'.$this->occurrenceOn($viaPlanner, self::TODAY)->id.'/skip')
            ->assertOk();
        $this->putJson("/api/routines/{$viaToday->id}/logs/".self::TODAY, ['status' => 'skipped'])
            ->assertOk();

        $plannerLog = RoutineLog::query()->where('routine_id', $viaPlanner->id)->firstOrFail();
        $todayLog = RoutineLog::query()->where('routine_id', $viaToday->id)->firstOrFail();

        $comparable = fn (RoutineLog $log): array => [
            'status' => $log->status,
            'log_date' => $log->log_date->format('Y-m-d'),
            'note' => $log->note,
            'completed_at' => $log->completed_at,
        ];

        $this->assertSame($comparable($todayLog), $comparable($plannerLog));
    }

    public function test_progress_and_streaks_are_unchanged_by_planner_actions(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $this->actingAs($owner);

        $viaToday = app(RoutineProgressService::class)->calculate($owner, self::TODAY);

        $this->putJson('/api/planner/occurrences/'.$this->occurrenceOn($routine, self::TODAY)->id.'/skip')
            ->assertOk();

        $afterSkip = app(RoutineProgressService::class)->calculate($owner->fresh(), self::TODAY);

        // A planner skip lands in the same place a Today skip does, so progress
        // moves exactly as it always has: one scheduled day, now skipped.
        $this->assertSame($viaToday['seven_day']['scheduled'], $afterSkip['seven_day']['scheduled']);
        $this->assertSame(1, $afterSkip['seven_day']['skipped']);
        $this->assertSame(0, $afterSkip['seven_day']['done']);
    }

    /* ---------------------------------------------------------------- */
    /* Storage items */
    /* ---------------------------------------------------------------- */

    public function test_moving_a_task_goes_through_storage_and_duplicates_nothing(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner, 'Order tiles', self::TODAY);
        $this->actingAs($owner);

        $this->patchJson("/api/storage/items/{$item->id}", ['due_on' => self::TOMORROW])->assertOk();

        $this->assertSame(self::TOMORROW, $item->fresh()->due_on->format('Y-m-d'));

        $today = array_column($this->getJson('/api/planner/day')->json('entries'), 'title');
        $tomorrow = array_column($this->getJson('/api/planner/day?date='.self::TOMORROW)->json('entries'), 'title');

        $this->assertNotContains('Order tiles', $today);
        $this->assertContains('Order tiles', $tomorrow);

        // The item moved; nothing about it was copied into Planner.
        $this->assertSame(0, DB::table('time_blocks')->count());
    }
}
