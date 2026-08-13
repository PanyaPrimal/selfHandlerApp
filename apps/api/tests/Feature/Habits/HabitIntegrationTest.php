<?php

namespace Tests\Feature\Habits;

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\InAppNotification;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationSourceSynchronizer;
use App\Services\Planner\SourceRegistry;
use Carbon\CarbonImmutable;

class HabitIntegrationTest extends HabitTestCase
{
    public function test_planner_registry_projects_habit_occurrence_without_copying_it(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner, [
            'name' => 'Read',
            'mode' => Habit::MODE_NUMERIC,
            'target_value' => 20,
            'unit' => 'pages',
            'preferred_time' => '08:30',
        ]);

        $response = $this->actingAs($owner)->getJson('/api/planner/day?date='.self::TODAY)
            ->assertOk()
            ->assertJsonPath('sources', app(SourceRegistry::class)->names());

        $entry = collect($response->json('entries'))->firstWhere('source', 'habit');
        $this->assertNotNull($entry);
        $this->assertSame($this->occurrenceOn($habit)->id, $entry['source_id']);
        $this->assertSame('08:30', $entry['time']);
        $this->assertSame($habit->id, $entry['meta']['habit_id']);
        $this->assertContains('reschedule', $entry['actions']);
        $this->assertDatabaseCount('time_blocks', 0);
    }

    public function test_generic_reschedule_moves_untouched_habit_and_refuses_completed_fact(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner, [], ['TH']);
        $occurrence = $this->occurrenceOn($habit);
        $this->actingAs($owner)
            ->patchJson("/api/planner/occurrences/{$occurrence->id}/reschedule", [
                'rescheduled_to' => '2026-08-14',
            ])->assertOk()
            ->assertJsonPath('data.rescheduled_to', '2026-08-14');

        $this->createLog($habit, $owner, '2026-08-14', [
            'outcome' => HabitLog::OUTCOME_DONE,
            'occurred_time' => '08:00',
        ]);

        $this->patchJson("/api/planner/occurrences/{$occurrence->id}/reschedule", [
            'rescheduled_to' => '2026-08-21',
        ])->assertUnprocessable();
    }

    public function test_timed_habit_notification_is_deduplicated_localized_and_closed_by_fact(): void
    {
        $owner = $this->createUser(locale: 'ru-UA');
        $habit = $this->createHabit($owner, [
            'name' => 'Читать',
            'preferred_time' => '08:30',
        ]);
        $occurrence = $this->occurrenceOn($habit);
        $sync = app(NotificationSourceSynchronizer::class);
        $now = CarbonImmutable::parse(self::TODAY.' 09:00:00 UTC');

        $this->assertSame(1, $sync->synchronize($owner, $now));
        $this->assertSame(0, $sync->synchronize($owner, $now));
        $this->assertSame(1, app(NotificationDispatcher::class)->dispatchForUser($owner, $now));

        $notification = InAppNotification::query()->firstOrFail();
        $this->assertSame(InAppNotification::TYPE_HABIT_REMINDER, $notification->type);
        $this->assertSame(InAppNotification::CATEGORY_HABIT, $notification->category);
        $this->assertSame($occurrence->id, $notification->source_id);
        $this->assertStringContainsString('Читать', $notification->body);
        $this->assertSame('/planner?date='.self::TODAY, $notification->action_url);

        $this->createLog($habit, $owner, self::TODAY, [
            'outcome' => HabitLog::OUTCOME_DONE,
            'occurred_time' => '09:05',
        ]);
        $sync->synchronize($owner, $now);
        $this->assertSame(InAppNotification::STATUS_ACTIONED, $notification->fresh()->status);
    }

    public function test_untimed_or_disabled_habit_creates_no_direct_notification_and_default_is_backwards_compatible(): void
    {
        $owner = $this->createUser();
        $this->createHabit($owner);
        $settings = $owner->ensureNotificationSettings();

        $this->assertTrue($settings->categorySettings()['habit']);
        $this->actingAs($owner)->putJson('/api/notifications/settings', [
            'quiet_hours' => ['enabled' => true, 'starts_at' => '23:00', 'ends_at' => '08:00'],
            'digest' => ['enabled' => true, 'time' => '08:00'],
            'categories' => ['routine' => true, 'storage' => true],
        ])->assertOk()->assertJsonPath('data.categories.habit', true);
        $this->assertSame(0, app(NotificationSourceSynchronizer::class)->synchronize(
            $owner,
            CarbonImmutable::parse(self::TODAY.' 09:00:00 UTC'),
        ));

        $settings->update(['categories' => ['routine' => true, 'storage' => true, 'habit' => false]]);
        $this->createHabit($owner, ['name' => 'Timed', 'preferred_time' => '08:30']);
        $this->assertSame(0, app(NotificationSourceSynchronizer::class)->synchronize(
            $owner,
            CarbonImmutable::parse(self::TODAY.' 09:00:00 UTC'),
        ));
    }

    public function test_paused_and_archived_habits_stop_future_planning_and_reminders_but_keep_facts(): void
    {
        $owner = $this->createUser();
        $habit = $this->createHabit($owner, ['preferred_time' => '08:30']);
        $this->createLog($habit, $owner, self::TODAY, [
            'outcome' => HabitLog::OUTCOME_DONE,
            'occurred_time' => '08:00',
        ]);
        $this->actingAs($owner)->patchJson("/api/habits/{$habit->id}", [
            'is_active' => false,
            'is_archived' => true,
        ])->assertOk();

        $this->assertDatabaseHas('habit_logs', ['habit_id' => $habit->id, 'log_date' => self::TODAY]);
        $this->assertDatabaseMissing('planned_occurrences', [
            'recurring_rule_id' => $habit->recurringRule->id,
            'occurrence_date' => '2026-08-14',
        ]);
        $this->assertSame(0, app(NotificationSourceSynchronizer::class)->synchronize(
            $owner,
            CarbonImmutable::parse(self::TODAY.' 09:00:00 UTC'),
        ));
    }
}
