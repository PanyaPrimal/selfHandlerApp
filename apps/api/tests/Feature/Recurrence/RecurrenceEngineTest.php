<?php

namespace Tests\Feature\Recurrence;

use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\Routine;
use App\Models\RoutineLog;
use App\Services\OccurrenceFactSynchronizer;
use App\Services\RecurrenceMaterializer;
use App\Services\RecurringRuleExpander;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RecurrenceEngineTest extends RecurrenceTestCase
{
    private function expander(): RecurringRuleExpander
    {
        return app(RecurringRuleExpander::class);
    }

    private function materializer(): RecurrenceMaterializer
    {
        return app(RecurrenceMaterializer::class);
    }

    /* ---------------------------------------------------------------- */
    /* Expansion */
    /* ---------------------------------------------------------------- */

    public function test_expansion_covers_daily_weekly_and_bounds(): void
    {
        $owner = $this->createUser();
        $daily = $this->ruleFor($this->createRoutine($owner, ['starts_on' => '2026-08-10', 'ends_on' => '2026-08-12']));
        $weekly = $this->ruleFor($this->createRoutine($owner, [], ['MO', 'WE']));

        $this->assertSame(
            ['2026-08-10', '2026-08-11', '2026-08-12'],
            $this->expander()->datesBetween($daily, '2026-08-01', '2026-08-31'),
            'Bounds are inclusive on both ends.',
        );

        $this->assertSame(
            ['2026-08-10', '2026-08-12', '2026-08-17', '2026-08-19'],
            $this->expander()->datesBetween($weekly, '2026-08-10', '2026-08-20'),
        );

        $this->assertTrue($this->expander()->occursOn($weekly, '2026-08-10'));
        $this->assertFalse($this->expander()->occursOn($weekly, '2026-08-11'));
        $this->assertFalse($this->expander()->occursOn($daily, '2026-08-09'));
        $this->assertFalse($this->expander()->occursOn($daily, '2026-08-13'));
    }

    public function test_a_weekly_rule_without_weekdays_and_an_unknown_frequency_never_occur(): void
    {
        $owner = $this->createUser();
        $empty = $this->ruleFor($this->createRoutine($owner, ['schedule_type' => 'weekdays']));
        $unknown = $this->ruleFor($this->createRoutine($owner, ['schedule_type' => 'future_engine_rule']));

        $this->assertSame([], $this->expander()->datesBetween($empty, '2026-08-01', '2026-08-31'));
        $this->assertSame([], $this->expander()->datesBetween($unknown, '2026-08-01', '2026-08-31'));
    }

    /* ---------------------------------------------------------------- */
    /* Time zones */
    /* ---------------------------------------------------------------- */

    public function test_two_users_expand_in_their_own_zone(): void
    {
        $kyiv = $this->createUser('kyiv@example.test', 'Europe/Kyiv');
        $auckland = $this->createUser('auckland@example.test', 'Pacific/Auckland');

        $kyivRule = $this->ruleFor($this->createRoutine($kyiv, [], ['MO']));
        $aucklandRule = $this->ruleFor($this->createRoutine($auckland, [], ['MO']));

        $this->assertSame('Europe/Kyiv', $kyivRule->timezone);
        $this->assertSame('Pacific/Auckland', $aucklandRule->timezone);

        // The same calendar day is a Monday in both zones; the point is that each
        // rule resolves it against its own zone rather than a shared server one.
        $this->assertTrue($this->expander()->occursOn($kyivRule, '2026-08-10'));
        $this->assertTrue($this->expander()->occursOn($aucklandRule, '2026-08-10'));
        $this->assertFalse($this->expander()->occursOn($kyivRule, '2026-08-11'));
    }

    public function test_daylight_saving_transitions_neither_duplicate_nor_drop_a_day(): void
    {
        $owner = $this->createUser('dst@example.test', 'Europe/Kyiv');
        $rule = $this->ruleFor($this->createRoutine($owner));

        // Spring forward: 2026-03-29 in Kyiv loses an hour.
        $spring = $this->expander()->datesBetween($rule, '2026-03-27', '2026-03-31');
        $this->assertSame(
            ['2026-03-27', '2026-03-28', '2026-03-29', '2026-03-30', '2026-03-31'],
            $spring,
        );
        $this->assertSame($spring, array_values(array_unique($spring)));

        // Fall back: 2026-10-25 in Kyiv repeats an hour.
        $autumn = $this->expander()->datesBetween($rule, '2026-10-23', '2026-10-27');
        $this->assertSame(
            ['2026-10-23', '2026-10-24', '2026-10-25', '2026-10-26', '2026-10-27'],
            $autumn,
        );
        $this->assertSame($autumn, array_values(array_unique($autumn)));
    }

    /* ---------------------------------------------------------------- */
    /* Materialization */
    /* ---------------------------------------------------------------- */

    public function test_the_window_equals_the_expansion_and_is_idempotent(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner, [], ['MO', 'TH']);
        $rule = $this->ruleFor($routine);

        $this->materializer()->materialize($rule, '2026-08-10');
        $first = $this->windowDates($rule);

        $this->assertSame(
            $this->expander()->datesBetween($rule, '2026-08-10', '2026-11-08'),
            $first,
            'The materialized window must equal the expansion over the same range.',
        );

        $this->materializer()->materialize($rule->fresh(), '2026-08-10');

        $this->assertSame($first, $this->windowDates($rule), 'A repeated run must converge.');
        $this->assertSame('2026-11-08', $rule->fresh()->last_materialized_until->format('Y-m-d'));
    }

    public function test_the_window_respects_the_end_date_and_a_disabled_owner(): void
    {
        $owner = $this->createUser();
        $bounded = $this->ruleFor($this->createRoutine($owner, ['ends_on' => '2026-08-15']));

        $this->materializer()->materialize($bounded, '2026-08-10');

        $this->assertSame(
            ['2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13', '2026-08-14', '2026-08-15'],
            $this->windowDates($bounded),
        );

        $paused = $this->ruleFor($this->createRoutine($owner, [], [], ['name' => 'Paused', 'is_active' => false]));
        $this->materializer()->materialize($paused, '2026-08-10', enabled: false);

        $this->assertSame([], $this->windowDates($paused));
    }

    public function test_a_rule_change_regenerates_unmarked_days_and_keeps_linked_ones(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $rule = $this->ruleFor($routine);

        $this->materializer()->materialize($rule, '2026-08-10');
        $this->assertContains('2026-08-11', $this->windowDates($rule));

        // Link one day to a fact, then narrow the rule so neither day expands.
        $log = RoutineLog::create([
            'user_id' => $owner->id,
            'routine_id' => $routine->id,
            'log_date' => '2026-08-11',
            'status' => 'done',
        ]);
        app(OccurrenceFactSynchronizer::class)->syncFromLog($log);

        $rule->update(['ends_on' => '2026-08-10']);
        $this->materializer()->materialize($rule->fresh(), '2026-08-10');

        $remaining = $this->windowDates($rule);

        $this->assertContains('2026-08-10', $remaining, 'The still-expanded day survives.');
        $this->assertContains(
            '2026-08-11',
            $remaining,
            'A day linked to a fact is evidence and must not be deleted.',
        );
        $this->assertNotContains('2026-08-12', $remaining, 'An unmarked day the rule dropped is removed.');
    }

    public function test_a_moved_day_is_intent_and_survives_materialization(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $rule = $this->ruleFor($routine);

        $this->materializer()->materialize($rule, self::TODAY);

        $moved = PlannedOccurrence::query()
            ->where('recurring_rule_id', $rule->id)
            ->where('occurrence_date', '2026-08-11')
            ->firstOrFail();

        $moved->forceFill(['rescheduled_to' => '2026-08-20'])->save();

        // Re-running with the day still expanded must not disturb the move.
        $this->materializer()->materialize($rule->fresh(), self::TODAY);
        $this->assertSame('2026-08-20', $moved->fresh()->rescheduled_to?->format('Y-m-d'));

        // Now narrow the rule so the original day no longer expands. The user
        // deliberately moved that day; deleting it would silently drop the
        // commitment from the date they moved it to.
        $rule->update(['ends_on' => self::TODAY]);
        $this->materializer()->materialize($rule->fresh(), self::TODAY);

        $survivor = PlannedOccurrence::query()->find($moved->id);

        $this->assertNotNull($survivor, 'A day the user moved is intent, not a prediction.');
        $this->assertSame('2026-08-20', $survivor->rescheduled_to?->format('Y-m-d'));

        // A day that was neither moved nor logged is still dropped as before.
        $this->assertNotContains('2026-08-12', $this->windowDates($rule->fresh()));
    }

    public function test_materialization_uses_a_bounded_number_of_queries(): void
    {
        $owner = $this->createUser();

        for ($index = 0; $index < 50; $index++) {
            $this->createRoutine($owner, [], [], ['name' => 'Routine '.$index]);
        }

        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $this->materializer()->materializeForUser($owner->fresh(), '2026-08-10');
            $queries = count($connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
        }

        // Roughly a handful per rule, plus the shared lookups. The point is that
        // it does not grow with the 91 days in each window.
        $this->assertLessThanOrEqual(
            50 * 8 + 20,
            $queries,
            'Materialization must not scale with the number of days in the window.',
        );
        $this->assertSame(50 * 91, PlannedOccurrence::query()->ownedBy($owner)->count());
    }

    /* ---------------------------------------------------------------- */
    /* Facts */
    /* ---------------------------------------------------------------- */

    public function test_an_occurrence_follows_the_log_and_can_be_rebuilt_from_it(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $this->actingAs($owner);

        $this->assertSame(
            ['2026-08-10', '2026-08-11', '2026-08-12'],
            array_slice($this->windowDates($this->ruleFor($routine)), 0, 3),
            'The window must start at the frozen current day.',
        );

        $this->putJson("/api/routines/{$routine->id}/logs/2026-08-11", ['status' => 'done'])->assertOk();

        $occurrence = $this->occurrenceOn($routine, '2026-08-11');
        $this->assertSame(PlannedOccurrence::STATUS_DONE, $occurrence->status);
        $this->assertNotNull($occurrence->routine_log_id);

        $this->putJson("/api/routines/{$routine->id}/logs/2026-08-11", ['status' => 'skipped'])->assertOk();
        $this->assertSame(PlannedOccurrence::STATUS_SKIPPED, $this->occurrenceOn($routine, '2026-08-11')->status);

        $this->deleteJson("/api/routines/{$routine->id}/logs/2026-08-11")->assertNoContent();

        $cleared = $this->occurrenceOn($routine, '2026-08-11');
        $this->assertSame(PlannedOccurrence::STATUS_PLANNED, $cleared->status);
        $this->assertNull($cleared->routine_log_id);

        // The derived status is recomputable from the logs alone.
        $this->putJson("/api/routines/{$routine->id}/logs/2026-08-12", ['status' => 'done'])->assertOk();
        PlannedOccurrence::query()->ownedBy($owner)->update([
            'status' => PlannedOccurrence::STATUS_PLANNED,
            'routine_log_id' => null,
        ]);

        app(OccurrenceFactSynchronizer::class)->reconcile($owner);

        $this->assertSame(PlannedOccurrence::STATUS_DONE, $this->occurrenceOn($routine, '2026-08-12')->status);
    }

    /* ---------------------------------------------------------------- */
    /* Ownership */
    /* ---------------------------------------------------------------- */

    public function test_rules_and_occurrences_stay_with_their_owner(): void
    {
        $owner = $this->createUser('owner@example.test');
        $other = $this->createUser('other@example.test');

        $ownerRoutine = $this->createRoutine($owner);
        $otherRoutine = $this->createRoutine($other);

        $this->assertSame(
            [$this->ruleFor($ownerRoutine)->id],
            RecurringRule::query()->ownedBy($owner)->pluck('id')->all(),
        );

        $this->expectException(RuntimeException::class);

        PlannedOccurrence::create([
            'user_id' => $other->id,
            'recurring_rule_id' => $this->ruleFor($ownerRoutine)->id,
            'occurrence_date' => '2026-08-11',
            'slot' => '',
            'status' => PlannedOccurrence::STATUS_PLANNED,
        ]);
    }

    /**
     * @return list<string>
     */
    private function windowDates(RecurringRule $rule): array
    {
        return PlannedOccurrence::query()
            ->where('recurring_rule_id', $rule->id)
            ->orderBy('occurrence_date')
            ->pluck('occurrence_date')
            ->map(fn ($date): string => $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr((string) $date, 0, 10))
            ->all();
    }

    private function occurrenceOn(Routine $routine, string $date): PlannedOccurrence
    {
        return PlannedOccurrence::query()
            ->where('recurring_rule_id', $this->ruleFor($routine)->id)
            ->where('occurrence_date', $date)
            ->firstOrFail();
    }
}
