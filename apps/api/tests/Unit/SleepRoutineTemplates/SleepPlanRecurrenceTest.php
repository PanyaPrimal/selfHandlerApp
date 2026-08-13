<?php

namespace Tests\Unit\SleepRoutineTemplates;

use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\SleepLog;
use App\Models\SleepOccurrenceDetail;
use App\Services\OccurrenceFactSynchronizer;
use App\Services\RecurrenceMaterializer;
use App\Services\SleepPlanRecurrence;
use Illuminate\Support\Facades\DB;
use Tests\Feature\SleepRoutineTemplates\SleepRoutineTestCase;

class SleepPlanRecurrenceTest extends SleepRoutineTestCase
{
    public function test_sleep_is_a_third_rule_owner_without_numeric_id_collision(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner);
        $plan = $this->createSleepPlan($owner);

        $this->assertSame($routine->id, $plan->id, 'Fixture proves ids collide across owner tables.');
        $this->assertSame(RecurringRule::OWNER_SLEEP_PLAN, $plan->recurringRule->owner_type);
        $this->assertSame('23:00', substr((string) $plan->recurringRule->slot_time, 0, 5));

        $routine->update(['is_active' => false]);
        app(RecurrenceMaterializer::class)->materializeForUser($owner, self::TODAY);

        $this->assertSame(0, PlannedOccurrence::query()
            ->where('recurring_rule_id', $routine->recurringRule->id)->count());
        $this->assertGreaterThan(0, PlannedOccurrence::query()
            ->where('recurring_rule_id', $plan->recurringRule->id)->count());
    }

    public function test_every_plan_and_global_materialization_path_writes_one_wake_snapshot_per_occurrence(): void
    {
        $owner = $this->createUser();
        $plan = $this->createSleepPlan($owner);
        $materializer = app(RecurrenceMaterializer::class);

        $occurrenceCount = $plan->recurringRule->occurrences()->count();
        $this->assertGreaterThan(0, $occurrenceCount);
        $this->assertSame($occurrenceCount, SleepOccurrenceDetail::query()->count());

        $materializer->materialize($plan->recurringRule->fresh('ruleWeekdays'), self::TODAY, true);
        $materializer->materializeForUser($owner, self::TODAY);

        $this->assertSame($occurrenceCount, $plan->recurringRule->occurrences()->count());
        $this->assertSame($occurrenceCount, SleepOccurrenceDetail::query()->count());
        $this->assertSame(0, PlannedOccurrence::query()
            ->where('recurring_rule_id', $plan->recurringRule->id)
            ->whereDoesntHave('sleepDetail')
            ->count());
    }

    public function test_plan_edits_update_only_unlinked_future_wake_snapshots(): void
    {
        $owner = $this->createUser();
        $plan = $this->createSleepPlan($owner);
        $linked = $this->sleepOccurrenceOn($plan, self::TODAY);
        $future = $this->sleepOccurrenceOn($plan, self::TOMORROW);
        $log = SleepLog::create([
            'user_id' => $owner->id,
            'sleep_plan_id' => $plan->id,
            'sleep_date' => self::TODAY,
            'actual_bed_at' => self::TODAY.' 23:15:00',
            'actual_wake_at' => self::TOMORROW.' 07:10:00',
            'quality' => 8,
        ]);
        $linked->update(['sleep_log_id' => $log->id, 'status' => PlannedOccurrence::STATUS_DONE]);

        $plan->update(['planned_wake_time' => '08:00']);
        app(SleepPlanRecurrence::class)->apply($plan->fresh(), $owner, [
            'planned_bed_time' => '22:30',
        ], null);

        $this->assertSame('07:00', substr((string) $linked->sleepDetail->fresh()->planned_wake_time, 0, 5));
        $this->assertSame('08:00', substr((string) $future->sleepDetail->fresh()->planned_wake_time, 0, 5));
        $this->assertSame('23:00', substr((string) $linked->fresh()->occurrence_time, 0, 5));
        $this->assertSame('22:30', substr((string) $future->fresh()->occurrence_time, 0, 5));
        $this->assertSame($log->id, $linked->fresh()->sleep_log_id);
    }

    public function test_rescheduled_sleep_occurrence_survives_rule_edits_with_its_snapshot(): void
    {
        $owner = $this->createUser();
        $plan = $this->createSleepPlan($owner);
        $occurrence = $this->sleepOccurrenceOn($plan);
        $detailId = $occurrence->sleepDetail->id;
        $occurrence->update(['rescheduled_to' => '2026-08-20']);

        app(SleepPlanRecurrence::class)->apply($plan, $owner, [
            'schedule_type' => 'weekdays',
        ], ['MO']);

        $this->assertSame('2026-08-20', $occurrence->fresh()->rescheduled_to->format('Y-m-d'));
        $this->assertSame($detailId, $occurrence->sleepDetail->fresh()->id);
    }

    public function test_reconcile_rebuilds_sleep_fact_mirror_without_changing_the_fact_or_snapshot(): void
    {
        $owner = $this->createUser();
        $plan = $this->createSleepPlan($owner);
        $occurrence = $this->sleepOccurrenceOn($plan);
        $detail = $occurrence->sleepDetail->replicate()->toArray();
        $log = SleepLog::create([
            'user_id' => $owner->id,
            'sleep_plan_id' => $plan->id,
            'sleep_date' => self::TODAY,
            'actual_bed_at' => self::TODAY.' 23:00:00',
            'actual_wake_at' => self::TOMORROW.' 07:00:00',
            'quality' => 9,
        ]);
        $occurrence->forceFill(['sleep_log_id' => null, 'status' => PlannedOccurrence::STATUS_PLANNED])->save();

        app(OccurrenceFactSynchronizer::class)->reconcile($owner);

        $this->assertSame($log->id, $occurrence->fresh()->sleep_log_id);
        $this->assertSame(PlannedOccurrence::STATUS_DONE, $occurrence->fresh()->status);
        $this->assertSame(9, $log->fresh()->quality);
        $this->assertEquals($detail, $occurrence->sleepDetail->fresh()->replicate()->toArray());
    }

    public function test_one_rule_materialization_has_a_fixed_query_budget(): void
    {
        $owner = $this->createUser();
        $plan = $this->createSleepPlan($owner);
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(RecurrenceMaterializer::class)->materialize(
            $plan->recurringRule->fresh('ruleWeekdays'),
            self::TODAY,
            true,
        );

        $this->assertLessThanOrEqual(12, count(DB::getQueryLog()));
    }
}
