<?php

namespace Tests\Feature\WorkoutsTrainingGoals;

use App\Models\DailyReview;
use App\Models\InAppNotification;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationSourceSynchronizer;
use App\Services\Planner\TrainingGoalSource;
use App\Services\TrainingGoalService;
use Carbon\CarbonImmutable;

class WorkoutIntegrationTest extends WorkoutTestCase
{
    public function test_today_and_review_transport_the_same_workout_summary_without_copying_it(): void
    {
        $owner = $this->createUser();
        $program = $this->createProgram($owner);
        $this->addPrescription($program);
        $this->createPlannedSession($program, $owner);
        $review = DailyReview::create([
            'user_id' => $owner->id, 'review_date' => self::TODAY, 'mood' => 8,
            'notes' => 'Good training', 'completed_at' => now(),
        ]);
        $before = $review->fresh()->getAttributes();
        $this->actingAs($owner);

        $summary = $this->getJson('/api/today?date='.self::TODAY)
            ->assertOk()
            ->assertJsonPath('module_summaries.workouts.planned', 1)
            ->assertJsonPath('module_summaries.workouts.completed', 1)
            ->assertJsonPath('module_summaries.workouts.skipped', 0)
            ->assertJsonPath('module_summaries.workouts.duration_seconds', 3600)
            ->json('module_summaries.workouts');

        $this->assertSame($summary, $this->getJson('/api/today?date='.self::TODAY)
            ->json('module_summaries.workouts'));
        $this->assertSame($before, $review->fresh()->getAttributes());
    }

    public function test_workout_planner_entry_dispatches_skip_reschedule_and_fact_closure(): void
    {
        $owner = $this->createUser();
        $program = $this->createProgram($owner, [], ['preferred_time' => '18:00'], ['TH']);
        $this->addPrescription($program);
        $occurrence = $this->occurrenceOn($program);
        $this->actingAs($owner);

        $entry = collect($this->getJson('/api/planner/day?date='.self::TODAY)
            ->assertOk()->json('entries'))->firstWhere('source', 'workout');
        $this->assertSame($occurrence->id, $entry['source_id']);
        $this->assertSame(['skip', 'reschedule'], $entry['actions']);
        $this->assertSame('/workouts?date='.self::TODAY.'&program='.$program->id, $entry['meta']['action_url']);

        $this->putJson("/api/planner/occurrences/{$occurrence->id}/skip")->assertOk();
        $this->assertSame('skipped', collect($this->getJson('/api/planner/day?date='.self::TODAY)
            ->json('entries'))->firstWhere('source', 'workout')['status']);
        $this->deleteJson('/api/workouts/'.$occurrence->fresh()->workout_session_id)->assertNoContent();

        $this->patchJson("/api/planner/occurrences/{$occurrence->id}/reschedule", [
            'rescheduled_to' => self::TOMORROW,
        ])->assertOk();
        $this->assertNull(collect($this->getJson('/api/planner/day?date='.self::TODAY)
            ->json('entries'))->firstWhere('source', 'workout'));
    }

    public function test_race_goal_is_one_read_only_planner_event_and_not_a_recurring_owner(): void
    {
        $owner = $this->createUser();
        $goal = app(TrainingGoalService::class)->create($owner, [
            'name' => 'Autumn 10K', 'kind' => 'race', 'activity' => 'running',
            'exercise_id' => null, 'workout_program_id' => null,
            'target_date' => '2026-10-10', 'target_value' => 10000,
        ]);
        $this->actingAs($owner);

        $entry = collect($this->getJson('/api/planner/day?date=2026-10-10')
            ->assertOk()->json('entries'))->firstWhere('source', 'training_goal');
        $this->assertSame($goal->id, $entry['source_id']);
        $this->assertSame([], $entry['actions']);
        $this->assertSame('/workouts?goal='.$goal->id, $entry['meta']['action_url']);
        $this->assertDatabaseMissing('recurring_rules', ['owner_id' => $goal->id]);
        $this->assertCount(1, app(TrainingGoalSource::class)->entriesFor($owner, '2026-10-10'));
    }

    public function test_workout_reminder_dedupes_localizes_honors_quiet_hours_and_closes(): void
    {
        $owner = $this->createUser(locale: 'ru-UA');
        $program = $this->createProgram($owner, [], ['preferred_time' => '23:00']);
        $this->addPrescription($program);
        $owner->ensureNotificationSettings()->update([
            'quiet_hours_enabled' => true, 'quiet_starts_at' => '22:00', 'quiet_ends_at' => '07:00',
            'categories' => ['routine' => true, 'sleep' => true, 'storage' => true, 'habit' => true, 'workout' => true],
        ]);
        $sync = app(NotificationSourceSynchronizer::class);

        $this->assertSame(1, $sync->synchronize($owner, CarbonImmutable::now()));
        $this->assertSame(0, $sync->synchronize($owner, CarbonImmutable::now()));
        app(NotificationDispatcher::class)->dispatchForUser(
            $owner,
            CarbonImmutable::parse(self::TODAY.' 23:00:00 UTC'),
        );
        app(NotificationDispatcher::class)->dispatchForUser(
            $owner,
            CarbonImmutable::parse('2026-08-14 07:00:00 UTC'),
        );

        $notification = InAppNotification::query()
            ->where('type', InAppNotification::TYPE_WORKOUT_REMINDER)->firstOrFail();
        $this->assertSame(InAppNotification::CATEGORY_WORKOUT, $notification->category);
        $this->assertSame('Напоминание о тренировке', $notification->title);
        $this->assertSame('/workouts?date='.self::TODAY.'&program='.$program->id, $notification->action_url);
        $this->assertSame('2026-08-14 07:00:00', $notification->scheduled_at->format('Y-m-d H:i:s'));

        $this->actingAs($owner);
        $this->patchJson('/api/workout-programs/'.$program->id, ['is_active' => false])->assertOk();
        $sync->synchronize($owner, CarbonImmutable::now());
        $this->assertSame(InAppNotification::STATUS_CANCELLED, $notification->fresh()->status);
    }
}
