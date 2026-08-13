<?php

namespace Tests\Feature\Notifications;

use App\Models\InAppNotification;

class NotificationApiTest extends NotificationTestCase
{
    public function test_inbox_views_are_bounded_newest_first_and_return_exact_unread_count(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $older = $this->createDelivered($owner, ['source_id' => 1, 'sent_at' => now()->subHour()]);
        $newer = $this->createDelivered($owner, ['source_id' => 2, 'sent_at' => now()]);
        $older->update(['status' => InAppNotification::STATUS_READ, 'read_at' => now()]);
        $this->createDelivered($owner, ['source_id' => 3, 'status' => InAppNotification::STATUS_SNOOZED]);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('views', ['all', 'unread'])
            ->assertJsonPath('snooze_options', [15, 60, 240, 1440]);

        $this->getJson('/api/notifications?view=unread')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'sent');

        $this->getJson('/api/notifications?view=invalid')->assertUnprocessable();
    }

    public function test_settings_defaults_are_read_and_complete_replacements_are_atomic(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $this->getJson('/api/notifications/settings')
            ->assertOk()
            ->assertJsonPath('data.quiet_hours.enabled', true)
            ->assertJsonPath('data.quiet_hours.starts_at', '23:00')
            ->assertJsonPath('data.digest.time', '08:00')
            ->assertJsonPath('data.categories.routine', true);

        $payload = [
            'quiet_hours' => ['enabled' => true, 'starts_at' => '22:30', 'ends_at' => '07:15'],
            'digest' => ['enabled' => false, 'time' => '09:00'],
            'categories' => ['routine' => true, 'storage' => false],
        ];

        $this->putJson('/api/notifications/settings', $payload)
            ->assertOk()
            ->assertJsonPath('data', $payload);

        $this->putJson('/api/notifications/settings', [
            ...$payload,
            'quiet_hours' => ['enabled' => true, 'starts_at' => '07:15', 'ends_at' => '07:15'],
            'unexpected' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['quiet_hours.ends_at', 'unexpected']);

        $this->getJson('/api/notifications/settings')->assertJsonPath('data', $payload);
    }

    public function test_read_dismiss_and_snooze_change_only_delivery_state(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);
        $read = $this->createDelivered($owner, ['source_id' => 10]);
        $dismissed = $this->createDelivered($owner, ['source_id' => 11]);
        $snoozed = $this->createDelivered($owner, ['source_id' => 12]);

        $this->putJson("/api/notifications/{$read->id}/read")
            ->assertOk()
            ->assertJsonPath('data.status', 'read')
            ->assertJsonPath('unread_count', 2);
        $this->putJson("/api/notifications/{$read->id}/read")->assertOk();

        $this->putJson("/api/notifications/{$dismissed->id}/dismiss")->assertNoContent();
        $this->putJson("/api/notifications/{$dismissed->id}/dismiss")->assertNoContent();

        $this->putJson("/api/notifications/{$snoozed->id}/snooze", ['minutes' => 60])
            ->assertOk()
            ->assertJsonPath('data.status', 'snoozed')
            ->assertJsonPath('data.snoozed_until', '2026-08-13T10:00:00.000000Z');

        $this->assertSame(InAppNotification::STATUS_READ, $read->fresh()->status);
        $this->assertSame(InAppNotification::STATUS_DISMISSED, $dismissed->fresh()->status);
        $this->assertSame(InAppNotification::STATUS_SNOOZED, $snoozed->fresh()->status);
    }

    public function test_invalid_transitions_and_unowned_ids_are_not_changed_or_exposed(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $foreign = $this->createDelivered($owner);
        $terminal = $this->createDelivered($other, [
            'source_id' => 99,
            'status' => InAppNotification::STATUS_ACTIONED,
            'actioned_at' => now(),
        ]);

        $this->actingAs($other);

        foreach (['read', 'dismiss', 'snooze'] as $action) {
            $body = $action === 'snooze' ? ['minutes' => 15] : [];
            $this->putJson("/api/notifications/{$foreign->id}/{$action}", $body)->assertNotFound();
        }

        $this->putJson("/api/notifications/{$terminal->id}/snooze", ['minutes' => 15])
            ->assertUnprocessable();
        $this->putJson("/api/notifications/{$terminal->id}/snooze", ['minutes' => 17])
            ->assertUnprocessable()->assertJsonValidationErrors('minutes');

        $this->assertSame(InAppNotification::STATUS_SENT, $foreign->fresh()->status);
        $this->assertSame(InAppNotification::STATUS_ACTIONED, $terminal->fresh()->status);
    }
}
