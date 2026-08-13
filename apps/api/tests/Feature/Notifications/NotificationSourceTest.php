<?php

namespace Tests\Feature\Notifications;

use App\Models\InAppNotification;
use App\Models\Item;
use App\Models\PlannedOccurrence;
use App\Services\Notifications\DailyDigestBuilder;
use App\Services\Notifications\NotificationSourceSynchronizer;
use Carbon\CarbonImmutable;

class NotificationSourceTest extends NotificationTestCase
{
    public function test_timed_occurrences_and_high_priority_due_tasks_create_direct_records_once(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner, ['preferred_time' => '08:45']);
        $occurrence = $this->occurrenceOn($routine);
        $item = $this->createItem($owner);
        $sync = app(NotificationSourceSynchronizer::class);

        $this->assertSame(2, $sync->synchronize($owner, CarbonImmutable::now()));
        $this->assertSame(0, $sync->synchronize($owner, CarbonImmutable::now()));

        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'source_type' => InAppNotification::SOURCE_PLANNED_OCCURRENCE,
            'source_id' => $occurrence->id,
            'type' => InAppNotification::TYPE_ROUTINE_REMINDER,
            'category' => InAppNotification::CATEGORY_ROUTINE,
            'status' => InAppNotification::STATUS_SCHEDULED,
            'escalation_count' => 0,
            'max_escalations' => 2,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'source_type' => InAppNotification::SOURCE_STORAGE_ITEM,
            'source_id' => $item->id,
            'type' => InAppNotification::TYPE_STORAGE_DUE,
            'category' => InAppNotification::CATEGORY_STORAGE,
            'max_escalations' => 0,
        ]);

        $routineNotification = InAppNotification::query()
            ->where('source_type', InAppNotification::SOURCE_PLANNED_OCCURRENCE)
            ->firstOrFail();
        $storageNotification = InAppNotification::query()
            ->where('source_type', InAppNotification::SOURCE_STORAGE_ITEM)
            ->firstOrFail();

        $this->assertSame('2026-08-13 08:45:00', $routineNotification->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-13 08:00:00', $storageNotification->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame('/planner?date=2026-08-13', $routineNotification->action_url);
    }

    public function test_digest_counts_only_untimed_and_non_high_priority_minor_sources(): void
    {
        $owner = $this->createUser();
        $this->createRoutine($owner, [], 'Untimed routine');
        $this->createRoutine($owner, ['preferred_time' => '08:30'], 'Timed routine');
        $this->createItem($owner, ['title' => 'Normal task', 'priority' => 'normal']);
        $this->createItem($owner, ['title' => 'High task']);

        app(NotificationSourceSynchronizer::class)->synchronize($owner, CarbonImmutable::now());
        $digest = app(DailyDigestBuilder::class)->build($owner, CarbonImmutable::now());

        $this->assertNotNull($digest);
        $this->assertSame(InAppNotification::TYPE_DAILY_DIGEST, $digest->type);
        $this->assertSame([
            'date' => self::TODAY,
            'total' => 2,
            'routine_count' => 1,
            'storage_count' => 1,
        ], $digest->content);

        $this->assertSame($digest->id, app(DailyDigestBuilder::class)
            ->build($owner, CarbonImmutable::now())?->id);
        $this->assertSame(3, InAppNotification::query()->count(), 'Two direct records plus one digest.');
    }

    public function test_empty_disabled_and_not_yet_due_digests_are_no_ops(): void
    {
        $owner = $this->createUser();
        $builder = app(DailyDigestBuilder::class);

        $this->assertNull($builder->build($owner, CarbonImmutable::now()));

        $this->createItem($owner, ['priority' => 'normal']);
        $this->assertNull($builder->build($owner, CarbonImmutable::parse(self::TODAY.' 07:59:00 UTC')));

        $owner->ensureNotificationSettings()->update(['digest_enabled' => false]);
        $this->assertNull($builder->build($owner, CarbonImmutable::now()));
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_source_reconciliation_closes_notifications_without_changing_domain_facts(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner, ['preferred_time' => '08:45']);
        $occurrence = $this->occurrenceOn($routine);
        $item = $this->createItem($owner);
        $sync = app(NotificationSourceSynchronizer::class);
        $sync->synchronize($owner, CarbonImmutable::now());

        $occurrence->update(['status' => PlannedOccurrence::STATUS_DONE]);
        $item->applyStatus(Item::STATUS_DONE);
        $item->save();
        $domain = [
            'occurrence' => $occurrence->fresh()->only(['status', 'routine_log_id', 'occurrence_date', 'occurrence_time']),
            'item' => $item->fresh()->only(['status', 'completed_at', 'due_on', 'priority']),
        ];

        $sync->synchronize($owner, CarbonImmutable::now());

        $this->assertDatabaseHas('notifications', [
            'source_type' => InAppNotification::SOURCE_PLANNED_OCCURRENCE,
            'source_id' => $occurrence->id,
            'status' => InAppNotification::STATUS_ACTIONED,
        ]);
        $this->assertDatabaseHas('notifications', [
            'source_type' => InAppNotification::SOURCE_STORAGE_ITEM,
            'source_id' => $item->id,
            'status' => InAppNotification::STATUS_ACTIONED,
        ]);
        $this->assertEquals($domain['occurrence'], $occurrence->fresh()->only(array_keys($domain['occurrence'])));
        $this->assertEquals($domain['item'], $item->fresh()->only(array_keys($domain['item'])));
    }

    public function test_skipped_overdue_moved_or_disabled_sources_cancel_pending_delivery(): void
    {
        $owner = $this->createUser();
        $routine = $this->createRoutine($owner, ['preferred_time' => '08:45']);
        $occurrence = $this->occurrenceOn($routine);
        $movedRoutine = $this->createRoutine($owner, ['preferred_time' => '08:30'], 'Moved routine');
        $movedOccurrence = $this->occurrenceOn($movedRoutine);
        $item = $this->createItem($owner);
        $sync = app(NotificationSourceSynchronizer::class);
        $sync->synchronize($owner, CarbonImmutable::now());

        $occurrence->update(['status' => PlannedOccurrence::STATUS_SKIPPED]);
        $movedOccurrence->update(['rescheduled_to' => '2026-08-14']);
        $item->update(['due_on' => '2026-08-14']);
        $sync->synchronize($owner, CarbonImmutable::now());

        $this->assertSame(3, InAppNotification::query()
            ->where('status', InAppNotification::STATUS_CANCELLED)->count());

        $owner->ensureNotificationSettings()->update([
            'categories' => ['routine' => false, 'storage' => false],
        ]);
        $sync->synchronize($owner, CarbonImmutable::now());
        $this->assertDatabaseCount('notifications', 3);
    }
}
