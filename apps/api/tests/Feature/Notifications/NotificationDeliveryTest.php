<?php

namespace Tests\Feature\Notifications;

use App\Models\InAppNotification;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationEscalator;
use App\Services\Notifications\NotificationSourceSynchronizer;
use Carbon\CarbonImmutable;

class NotificationDeliveryTest extends NotificationTestCase
{
    public function test_delivery_catalogs_cover_every_supported_profile_locale(): void
    {
        $expected = [
            'en-GB' => 'Routine reminder',
            'ru-UA' => 'Напоминание о рутине',
            'uk-UA' => 'Нагадування про рутину',
        ];

        foreach ($expected as $locale => $title) {
            $owner = $this->createUser(
                strtolower(substr($locale, 0, 2)).'@example.test',
                'UTC',
                $locale,
            );
            $this->createRoutine($owner, ['preferred_time' => '08:45']);
            app(NotificationSourceSynchronizer::class)->synchronize($owner, CarbonImmutable::now());

            app(NotificationDispatcher::class)->dispatchForUser($owner, CarbonImmutable::now());

            $this->assertSame($title, InAppNotification::query()
                ->ownedBy($owner)->firstOrFail()->title);
        }
    }

    public function test_due_in_app_delivery_renders_current_profile_locale_and_is_retry_safe(): void
    {
        $owner = $this->createUser(locale: 'ru-UA');
        $routine = $this->createRoutine($owner, ['preferred_time' => '08:45'], 'Утренняя прогулка');
        app(NotificationSourceSynchronizer::class)->synchronize($owner, CarbonImmutable::now());
        $dispatcher = app(NotificationDispatcher::class);

        $this->assertSame(1, $dispatcher->dispatchForUser($owner, CarbonImmutable::now()));
        $this->assertSame(0, $dispatcher->dispatchForUser($owner, CarbonImmutable::now()));

        $notification = InAppNotification::query()->firstOrFail();
        $this->assertSame(InAppNotification::STATUS_SENT, $notification->status);
        $this->assertSame(['in_app'], $notification->channels);
        $this->assertSame('Напоминание о рутине', $notification->title);
        $this->assertStringContainsString('Утренняя прогулка', $notification->body);
        $this->assertNotNull($notification->next_escalation_at);
        $this->assertSame('2026-08-13 09:30:00', $notification->next_escalation_at->format('Y-m-d H:i:s'));
    }

    public function test_quiet_hours_defer_delivery_to_the_next_local_end(): void
    {
        $owner = $this->createUser(timezone: 'Europe/Kyiv');
        $settings = $owner->ensureNotificationSettings();
        $settings->update([
            'quiet_hours_enabled' => true,
            'quiet_starts_at' => '10:00',
            'quiet_ends_at' => '14:00',
        ]);
        $routine = $this->createRoutine($owner, ['preferred_time' => '13:30']);
        $notification = $this->createDelivered($owner, [
            'source_id' => $this->occurrenceOn($routine)->id,
            'title' => null,
            'body' => null,
            'status' => InAppNotification::STATUS_SCHEDULED,
            'channels' => [],
            'sent_at' => null,
            'scheduled_at' => CarbonImmutable::parse('2026-08-13 10:30:00 UTC'),
        ]);

        $this->assertSame(0, app(NotificationDispatcher::class)->dispatchForUser(
            $owner,
            CarbonImmutable::parse('2026-08-13 10:30:00 UTC'),
        ));

        $this->assertSame(InAppNotification::STATUS_SCHEDULED, $notification->fresh()->status);
        $this->assertSame('2026-08-13 11:00:00', $notification->fresh()->scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_a_source_completed_after_sync_is_closed_instead_of_delivered(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner, ['preferred_time' => '08:45']);
        $occurrence = $this->occurrenceOn($routine);
        app(NotificationSourceSynchronizer::class)->synchronize($owner, CarbonImmutable::now());
        $occurrence->update(['status' => 'done']);

        $this->assertSame(0, app(NotificationDispatcher::class)
            ->dispatchForUser($owner, CarbonImmutable::now()));

        $notification = InAppNotification::query()->firstOrFail();
        $this->assertSame(InAppNotification::STATUS_ACTIONED, $notification->status);
        $this->assertNull($notification->sent_at);
        $this->assertSame([], $notification->channels);
    }

    public function test_due_snooze_returns_as_unread_and_restarts_escalation_interval(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner, ['preferred_time' => '08:45']);
        $notification = $this->createDelivered($owner, [
            'source_id' => $this->occurrenceOn($routine)->id,
            'status' => InAppNotification::STATUS_SNOOZED,
            'read_at' => now()->subHour(),
            'snoozed_until' => now(),
            'scheduled_at' => now(),
            'next_escalation_at' => null,
        ]);

        app(NotificationDispatcher::class)->dispatchForUser($owner, CarbonImmutable::now());

        $notification->refresh();
        $this->assertSame(InAppNotification::STATUS_SENT, $notification->status);
        $this->assertNull($notification->read_at);
        $this->assertNull($notification->snoozed_until);
        $this->assertSame('2026-08-13 09:30:00', $notification->next_escalation_at->format('Y-m-d H:i:s'));
    }

    public function test_escalation_creates_numbered_repeats_once_and_stops_at_the_maximum(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner, ['preferred_time' => '08:00']);
        $occurrence = $this->occurrenceOn($routine);
        app(NotificationSourceSynchronizer::class)->synchronize($owner, CarbonImmutable::now());
        $initial = InAppNotification::query()->firstOrFail();
        $initial->update([
            'status' => InAppNotification::STATUS_SENT,
            'sent_at' => now()->subMinutes(30),
            'next_escalation_at' => now(),
            'channels' => ['in_app'],
            'title' => 'Routine reminder',
            'body' => 'Morning walk is planned now.',
        ]);
        $escalator = app(NotificationEscalator::class);

        $this->assertSame(1, $escalator->scheduleForUser($owner, CarbonImmutable::now()));
        $this->assertSame(0, $escalator->scheduleForUser($owner, CarbonImmutable::now()));
        $repeat = InAppNotification::query()->where('escalation_count', 1)->firstOrFail();
        $this->assertSame($occurrence->id, $repeat->source_id);

        $repeat->update([
            'status' => InAppNotification::STATUS_SENT,
            'sent_at' => now(),
            'next_escalation_at' => now(),
        ]);
        $this->assertSame(1, $escalator->scheduleForUser($owner, CarbonImmutable::now()));
        $second = InAppNotification::query()->where('escalation_count', 2)->firstOrFail();
        $second->update([
            'status' => InAppNotification::STATUS_SENT,
            'next_escalation_at' => now(),
        ]);

        $this->assertSame(0, $escalator->scheduleForUser($owner, CarbonImmutable::now()));
        $this->assertDatabaseCount('notifications', 3);
        $this->assertSame('planned', $occurrence->fresh()->status);
    }

    public function test_dismissed_or_disabled_source_families_do_not_escalate(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner, ['preferred_time' => '08:00']);
        app(NotificationSourceSynchronizer::class)->synchronize($owner, CarbonImmutable::now());
        $initial = InAppNotification::query()->firstOrFail();
        $initial->update([
            'status' => InAppNotification::STATUS_DISMISSED,
            'dismissed_at' => now(),
            'next_escalation_at' => now(),
        ]);

        $this->assertSame(0, app(NotificationEscalator::class)
            ->scheduleForUser($owner, CarbonImmutable::now()));

        $initial->update([
            'status' => InAppNotification::STATUS_SENT,
            'dismissed_at' => null,
            'next_escalation_at' => now(),
        ]);
        $owner->ensureNotificationSettings()->update([
            'categories' => ['routine' => false, 'storage' => true],
        ]);

        $this->assertSame(0, app(NotificationEscalator::class)
            ->scheduleForUser($owner, CarbonImmutable::now()));
    }
}
