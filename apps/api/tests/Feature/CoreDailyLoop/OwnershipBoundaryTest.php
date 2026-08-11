<?php

namespace Tests\Feature\CoreDailyLoop;

use App\Models\DailyReview;
use App\Models\Goal;
use App\Models\RecurringRuleWeekday;
use App\Models\Routine;
use App\Models\RoutineLog;
use RuntimeException;

/**
 * The persistence-level ownership boundary of the core daily loop.
 *
 * The HTTP session boundary itself is covered by the authentication feature in
 * `Tests\Feature\Auth\OwnershipBoundaryTest`; this suite covers the domain
 * records, their schedule rows, and the reusable `ownedBy()` scope.
 */
class OwnershipBoundaryTest extends CoreDailyLoopTestCase
{
    private const DATE = '2026-08-10';

    public function test_scoped_reads_return_only_records_of_the_requested_owner(): void
    {
        $owner = $this->createUser('owner@example.test');
        $other = $this->createUser('other@example.test', name: 'Other Owner');

        $ownedRoutine = $this->createRoutine($owner, ['name' => 'Shared title'], ['MO', 'WE']);
        $otherRoutine = $this->createRoutine($other, ['name' => 'Shared title'], ['MO', 'WE']);
        $this->createLog($ownedRoutine, self::DATE);
        $this->createLog($otherRoutine, self::DATE, 'skipped');
        $ownedGoal = $this->createGoal($owner);
        $otherGoal = $this->createGoal($other);
        $this->createReview($owner, self::DATE, ['notes' => 'Owner review']);
        $this->createReview($other, self::DATE, ['notes' => 'Other review']);

        $this->assertSame([$ownedRoutine->id], Routine::query()->ownedBy($owner)->pluck('id')->all());
        $this->assertSame([$otherRoutine->id], Routine::query()->ownedBy($other)->pluck('id')->all());
        $this->assertSame([$ownedGoal->id], Goal::query()->ownedBy($owner)->pluck('id')->all());
        $this->assertSame([$otherGoal->id], Goal::query()->ownedBy($other)->pluck('id')->all());
        $this->assertSame(1, RoutineLog::query()->ownedBy($owner)->count());
        $this->assertSame(2, RecurringRuleWeekday::query()->ownedBy($owner)->count());
        $this->assertSame(['Owner review'], DailyReview::query()->ownedBy($owner)->pluck('notes')->all());

        $this->assertTrue($ownedRoutine->isOwnedBy($owner));
        $this->assertFalse($ownedRoutine->isOwnedBy($other));
        $this->assertTrue($ownedRoutine->isOwnedBy($owner->id));
    }

    public function test_schedule_weekdays_stay_with_the_routine_owner(): void
    {
        $owner = $this->createUser('owner@example.test');
        $routine = $this->createRoutine($owner, [], ['TU', 'TH']);

        $this->assertSame(['TU', 'TH'], $routine->weekdays);
        $this->assertDatabaseCount('recurring_rule_weekdays', 2);

        foreach (RecurringRuleWeekday::all() as $weekday) {
            $this->assertSame($owner->id, $weekday->user_id);
            $this->assertSame($routine->recurringRule->id, $weekday->recurring_rule_id);
        }

        $routine->recurringRule->syncWeekdays(['th', 'SA', 'SA', 'not-a-weekday']);

        $this->assertSame(['TH', 'SA'], $routine->fresh()->weekdays);
        $this->assertDatabaseCount('recurring_rule_weekdays', 2);
        $this->assertSame(2, RecurringRuleWeekday::query()->ownedBy($owner)->count());
    }

    public function test_records_cannot_be_stored_without_an_owner_or_moved_to_another_owner(): void
    {
        $owner = $this->createUser('owner@example.test');
        $other = $this->createUser('other@example.test', name: 'Other Owner');
        $routine = $this->createRoutine($owner);

        $this->assertOwnerRequired(fn () => Routine::create(['name' => 'Unowned routine']));
        $this->assertOwnerRequired(fn () => Goal::create(['name' => 'Unowned goal']));
        $this->assertOwnerRequired(fn () => RoutineLog::create([
            'routine_id' => $routine->id,
            'log_date' => self::DATE,
            'status' => 'done',
        ]));

        $this->expectException(RuntimeException::class);
        $routine->update(['user_id' => $other->id]);
    }

    public function test_routine_children_cannot_claim_an_owner_different_from_their_parent(): void
    {
        $owner = $this->createUser('owner@example.test');
        $other = $this->createUser('other@example.test', name: 'Other Owner');
        $routine = $this->createRoutine($owner);

        try {
            RoutineLog::create([
                'user_id' => $other->id,
                'routine_id' => $routine->id,
                'log_date' => self::DATE,
                'status' => 'done',
            ]);
            $this->fail('A routine log accepted an owner different from its routine.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('same owner', $exception->getMessage());
        }

        try {
            RecurringRuleWeekday::create([
                'user_id' => $other->id,
                'recurring_rule_id' => $routine->recurringRule->id,
                'weekday' => 'MO',
            ]);
            $this->fail('A rule weekday accepted an owner different from its rule.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('same owner', $exception->getMessage());
        }

        $this->assertDatabaseCount('routine_logs', 0);
        $this->assertDatabaseCount('recurring_rule_weekdays', 0);
    }

    public function test_cross_owner_requests_are_rejected_and_leave_no_trace(): void
    {
        $owner = $this->createUser('owner@example.test');
        $other = $this->createUser('other@example.test', name: 'Other Owner');
        $ownerRoutine = $this->createRoutine($owner, [], ['MO']);
        $otherRoutine = $this->createRoutine($other, [], ['MO']);
        $ownerGoal = $this->createGoal($owner);
        $otherGoal = $this->createGoal($other);

        $this->actingAs($owner);

        $this->patchJson("/api/routines/{$otherRoutine->id}", [
            'name' => 'Taken over',
            'weekdays' => ['SU'],
        ])->assertNotFound();
        $this->putJson("/api/routines/{$otherRoutine->id}/logs/".self::DATE, ['status' => 'done'])->assertNotFound();
        $this->postJson("/api/goals/{$ownerGoal->id}/routines/{$otherRoutine->id}")->assertNotFound();
        $this->postJson("/api/goals/{$otherGoal->id}/routines/{$ownerRoutine->id}")->assertNotFound();
        $this->deleteJson("/api/goals/{$otherGoal->id}/routines/{$otherRoutine->id}")->assertNotFound();

        $this->assertSame(['MO'], $otherRoutine->fresh()->weekdays);
        $this->assertSame('Morning walk', $otherRoutine->fresh()->name);
        $this->assertDatabaseCount('routine_logs', 0);
        $this->assertDatabaseCount('goal_routine', 0);
        $this->assertSame(2, RecurringRuleWeekday::query()->count());
        $this->assertSame(1, RecurringRuleWeekday::query()->ownedBy($other)->count());
    }

    public function test_owner_scoped_uniqueness_allows_the_same_schedule_for_two_accounts(): void
    {
        $owner = $this->createUser('owner@example.test');
        $other = $this->createUser('other@example.test', name: 'Other Owner');

        $ownerRoutine = $this->createRoutine($owner, ['name' => 'Evening review'], ['FR']);
        $otherRoutine = $this->createRoutine($other, ['name' => 'Evening review'], ['FR']);

        $this->createLog($ownerRoutine, self::DATE);
        $this->createLog($otherRoutine, self::DATE);
        $this->createReview($owner, self::DATE);
        $this->createReview($other, self::DATE);

        $this->assertDatabaseCount('recurring_rule_weekdays', 2);
        $this->assertDatabaseCount('routine_logs', 2);
        $this->assertDatabaseCount('daily_reviews', 2);
    }

    private function assertOwnerRequired(callable $write): void
    {
        try {
            $write();
            $this->fail('An unowned record was stored.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('requires an owner', $exception->getMessage());
        }
    }
}
