<?php

namespace Tests\Feature\Notifications;

use App\Models\InAppNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

class NotificationSchemaTest extends NotificationTestCase
{
    public function test_additive_notification_tables_have_the_complete_portable_shape(): void
    {
        $this->assertTrue(Schema::hasTable('notification_settings'));
        $this->assertTrue(Schema::hasColumns('notification_settings', [
            'id', 'user_id', 'quiet_hours_enabled', 'quiet_starts_at', 'quiet_ends_at',
            'digest_enabled', 'digest_time', 'categories', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('notifications'));
        $this->assertTrue(Schema::hasColumns('notifications', [
            'id', 'user_id', 'source_type', 'source_id', 'type', 'category', 'title', 'body',
            'action_url', 'content', 'scheduled_at', 'status', 'channels', 'escalation_count',
            'next_escalation_at', 'max_escalations', 'snoozed_until', 'sent_at', 'read_at',
            'dismissed_at', 'actioned_at', 'cancelled_at', 'created_at', 'updated_at',
        ]));

        foreach (['notification_settings', 'notifications'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']));
            }
        }
    }

    public function test_settings_are_recoverable_with_one_complete_default_per_user(): void
    {
        $user = $this->createUser();

        $settings = $user->ensureNotificationSettings();

        $this->assertTrue($settings->quiet_hours_enabled);
        $this->assertSame('23:00', $settings->quietStartsAt());
        $this->assertSame('08:00', $settings->quietEndsAt());
        $this->assertTrue($settings->digest_enabled);
        $this->assertSame('08:00', $settings->digestTime());
        $this->assertSame(
            ['routine' => true, 'storage' => true, 'habit' => true],
            $settings->categorySettings(),
        );
        $this->assertSame($settings->id, $user->ensureNotificationSettings()->id);
    }

    public function test_source_and_escalation_identity_is_unique_per_owner(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $first = $this->createDelivered($owner, ['source_id' => 41]);

        $this->createDelivered($other, ['source_id' => 41]);
        $this->createDelivered($owner, ['source_id' => 41, 'escalation_count' => 1]);

        $this->expectException(QueryException::class);

        InAppNotification::create($first->only([
            'user_id', 'source_type', 'source_id', 'type', 'category', 'title', 'body', 'action_url',
            'content', 'scheduled_at', 'status', 'channels', 'escalation_count', 'max_escalations', 'sent_at',
        ]));
    }

    public function test_owner_scopes_and_user_deletion_keep_accounts_separate(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $this->createDelivered($owner);
        $this->createDelivered($other);
        $owner->ensureNotificationSettings();

        $this->assertSame(1, InAppNotification::query()->ownedBy($owner)->count());
        $this->assertSame(1, InAppNotification::query()->ownedBy($other)->count());

        $owner->delete();

        $this->assertSame(1, InAppNotification::query()->count());
        $this->assertDatabaseMissing('notification_settings', ['user_id' => $owner->id]);
    }
}
