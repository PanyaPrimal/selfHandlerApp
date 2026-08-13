<?php

namespace Tests\Feature\SleepRoutineTemplates;

use App\Models\DailyReview;
use App\Models\InAppNotification;
use App\Models\PlannedOccurrence;
use App\Models\Routine;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationSourceSynchronizer;
use App\Services\RoutineActivityLogService;
use App\Services\RoutineActivityService;
use App\Services\RoutineDayProjectionService;
use App\Services\SleepLogService;
use Carbon\CarbonImmutable;

class SleepRoutineIntegrationTest extends SleepRoutineTestCase
{
    public function test_today_transports_owner_summaries_and_review_persists_no_copy(): void
    {
        $owner = $this->createUser();
        $plan = $this->createSleepPlan($owner);
        $routine = $this->createRoutine($owner);
        $activities = app(RoutineActivityService::class)->replace($routine, $owner, [
            ['name' => 'Water', 'sort_order' => 0],
            ['name' => 'Stretch', 'sort_order' => 1],
        ]);
        app(SleepLogService::class)->upsert($plan, $owner, self::TODAY, [
            'actual_bed_date' => self::TODAY,
            'actual_bed_time' => '23:00',
            'actual_wake_date' => self::TOMORROW,
            'actual_wake_time' => '07:00',
            'quality' => 8,
        ]);
        app(RoutineActivityLogService::class)->upsert(
            $routine,
            $activities[0],
            $owner,
            self::TODAY,
            ['status' => 'done'],
        );
        $review = DailyReview::create([
            'user_id' => $owner->id,
            'review_date' => self::TODAY,
            'mood' => 7,
            'notes' => 'Original review',
            'completed_at' => now(),
        ]);
        $reviewBefore = $review->fresh()->getAttributes();
        $this->actingAs($owner);

        $today = $this->getJson('/api/today?date='.self::TODAY)
            ->assertOk()
            ->assertJsonPath('module_summaries.sleep.selected_night.log.duration_minutes', 480)
            ->assertJsonPath('module_summaries.sleep.selected_night.log.quality', 8)
            ->assertJsonPath('module_summaries.routine_activities.scheduled', 2)
            ->assertJsonPath('module_summaries.routine_activities.done', 1)
            ->assertJsonPath('module_summaries.routine_activities.pending', 1)
            ->assertJsonPath('module_summaries.routine_activities.completion_rate', 50)
            ->assertJsonPath('summary.scheduled', 1)
            ->json('module_summaries');

        $reviewContext = $this->getJson('/api/today?date='.self::TODAY)->json('module_summaries');
        $this->assertSame($today, $reviewContext);
        $this->assertSame($reviewBefore, $review->fresh()->getAttributes());

        app(RoutineActivityLogService::class)->upsert(
            $routine,
            $activities[1],
            $owner,
            self::TODAY,
            ['status' => 'done'],
        );
        $this->getJson('/api/today?date='.self::TODAY)
            ->assertJsonPath('module_summaries.routine_activities.completion_rate', 100);
        $this->assertSame($reviewBefore, $review->fresh()->getAttributes());
    }

    public function test_today_planner_and_projection_agree_on_selected_templates_and_anytime(): void
    {
        $owner = $this->createUser();
        $selected = $this->createRoutine($owner, ['name' => 'Selected morning']);
        $unselected = $this->createRoutine($owner, ['name' => 'Unselected morning']);
        $evening = $this->createRoutine($owner, [
            'name' => 'Evening', 'day_period' => Routine::DAY_PERIOD_EVENING,
        ]);
        $anytime = $this->createRoutine($owner, [
            'name' => 'Anytime', 'day_period' => Routine::DAY_PERIOD_ANYTIME,
        ]);
        app(RoutineDayProjectionService::class)->replace($owner, self::TODAY, [
            'morning_routine_id' => $selected->id,
            'evening_routine_id' => $evening->id,
        ]);
        $this->actingAs($owner);

        $todayIds = array_column($this->getJson('/api/today?date='.self::TODAY)->json('routines'), 'id');
        $planner = $this->getJson('/api/planner/day?date='.self::TODAY)->assertOk()->json('entries');
        $plannerRoutineIds = array_values(array_filter(array_map(
            fn (array $entry): ?int => $entry['source'] === 'routine' ? $entry['meta']['routine_id'] : null,
            $planner,
        )));

        sort($todayIds);
        sort($plannerRoutineIds);
        $expected = [$selected->id, $evening->id, $anytime->id];
        sort($expected);
        $this->assertSame($expected, $todayIds);
        $this->assertSame($expected, $plannerRoutineIds);
        $this->assertNotContains($unselected->id, $todayIds);
    }

    public function test_sleep_planner_entry_has_wake_context_reschedule_only_and_fact_closure(): void
    {
        $owner = $this->createUser();
        $plan = $this->createSleepPlan($owner);
        $occurrence = $this->sleepOccurrenceOn($plan);
        $this->actingAs($owner);

        $entry = collect($this->getJson('/api/planner/day?date='.self::TODAY)
            ->assertOk()->json('entries'))->firstWhere('source', 'sleep');
        $this->assertSame($occurrence->id, $entry['source_id']);
        $this->assertSame(['reschedule'], $entry['actions']);
        $this->assertSame('07:00', $entry['meta']['planned_wake_time']);
        $this->assertSame('/routines?sleep_date='.self::TODAY, $entry['meta']['action_url']);

        $this->patchJson("/api/planner/occurrences/{$occurrence->id}/reschedule", [
            'rescheduled_to' => '2026-08-20',
        ])->assertOk();
        $this->assertNull(collect($this->getJson('/api/planner/day?date='.self::TODAY)->json('entries'))
            ->firstWhere('source', 'sleep'));

        $this->patchJson("/api/planner/occurrences/{$occurrence->id}/reschedule", [
            'rescheduled_to' => null,
        ])->assertOk();
        app(SleepLogService::class)->upsert($plan, $owner, self::TODAY, [
            'actual_bed_date' => self::TODAY,
            'actual_bed_time' => '23:00',
            'actual_wake_date' => self::TOMORROW,
            'actual_wake_time' => '07:00',
            'quality' => 8,
        ]);
        $closed = collect($this->getJson('/api/planner/day?date='.self::TODAY)->json('entries'))
            ->firstWhere('source', 'sleep');
        $this->assertSame('done', $closed['status']);
        $this->assertSame([], $closed['actions']);
        $this->putJson("/api/planner/occurrences/{$occurrence->id}/skip")->assertNotFound();
    }

    public function test_notifications_include_only_selected_timed_routine_and_sleep_and_close_on_lifecycle(): void
    {
        $owner = $this->createUser();
        $selected = $this->createRoutine($owner, ['name' => 'Selected'], ['preferred_time' => '10:00']);
        $this->createRoutine($owner, ['name' => 'Unselected'], ['preferred_time' => '10:15']);
        $untimed = $this->createRoutine($owner, [
            'name' => 'Anytime', 'day_period' => Routine::DAY_PERIOD_ANYTIME,
        ]);
        $plan = $this->createSleepPlan($owner);
        app(RoutineDayProjectionService::class)->replace($owner, self::TODAY, [
            'morning_routine_id' => $selected->id,
            'evening_routine_id' => null,
        ]);
        $owner->ensureNotificationSettings()->update([
            'categories' => ['routine' => true, 'sleep' => true, 'storage' => true, 'digest' => true],
        ]);
        $sync = app(NotificationSourceSynchronizer::class);

        $this->assertSame(2, $sync->synchronize($owner, CarbonImmutable::now()));
        $this->assertSame(0, $sync->synchronize($owner, CarbonImmutable::now()));
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseHas('notifications', [
            'type' => InAppNotification::TYPE_ROUTINE_REMINDER,
            'source_id' => $this->routineOccurrenceOn($selected)->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'type' => InAppNotification::TYPE_SLEEP_REMINDER,
            'category' => InAppNotification::CATEGORY_SLEEP,
            'source_id' => $this->sleepOccurrenceOn($plan)->id,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'source_id' => $this->routineOccurrenceOn($untimed)->id,
        ]);

        $this->actingAs($owner);
        $this->patchJson("/api/sleep/plans/{$plan->id}", ['is_active' => false])->assertOk();
        $sync->synchronize($owner, CarbonImmutable::now());
        $this->assertDatabaseHas('notifications', [
            'type' => InAppNotification::TYPE_SLEEP_REMINDER,
            'status' => InAppNotification::STATUS_CANCELLED,
        ]);
        $this->assertSame(0, PlannedOccurrence::query()
            ->where('recurring_rule_id', $plan->recurringRule->id)
            ->whereNull('sleep_log_id')
            ->count());
    }

    public function test_sleep_reminder_reuses_locale_quiet_hours_and_escalation_policy(): void
    {
        $owner = $this->createUser(locale: 'ru-UA');
        $plan = $this->createSleepPlan($owner);
        $owner->ensureNotificationSettings()->update([
            'quiet_hours_enabled' => true,
            'quiet_starts_at' => '22:00',
            'quiet_ends_at' => '07:00',
            'categories' => ['routine' => true, 'sleep' => true, 'storage' => true, 'digest' => true],
        ]);

        app(NotificationSourceSynchronizer::class)->synchronize(
            $owner,
            CarbonImmutable::parse(self::TODAY.' 21:00:00 UTC'),
        );
        app(NotificationDispatcher::class)->dispatchForUser(
            $owner,
            CarbonImmutable::parse(self::TODAY.' 23:00:00 UTC'),
        );
        app(NotificationDispatcher::class)->dispatchForUser(
            $owner,
            CarbonImmutable::parse('2026-08-14 07:00:00 UTC'),
        );

        $notification = InAppNotification::query()
            ->where('source_id', $this->sleepOccurrenceOn($plan)->id)
            ->where('type', InAppNotification::TYPE_SLEEP_REMINDER)
            ->firstOrFail();
        $this->assertSame('2026-08-14 07:00:00', $notification->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame(2, $notification->max_escalations);
        $this->assertSame('Напоминание о сне', $notification->title);
    }
}
