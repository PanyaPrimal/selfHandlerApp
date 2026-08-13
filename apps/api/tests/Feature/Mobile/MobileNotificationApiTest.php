<?php

namespace Tests\Feature\Mobile;

use App\Models\InAppNotification;

class MobileNotificationApiTest extends MobileTestCase
{
    public function test_mobile_client_idempotently_acknowledges_successful_local_presentation(): void
    {
        $owner = $this->createUser();
        $token = $this->issueToken($owner);
        $notification = $this->createDeliveredNotification($owner);

        foreach (range(1, 2) as $attempt) {
            $this->withHeaders($this->bearer($token))
                ->putJson("/api/mobile/notifications/{$notification->id}/presented")
                ->assertOk()
                ->assertExactJson([
                    'data' => [
                        'id' => $notification->id,
                        'status' => InAppNotification::STATUS_SENT,
                        'channels' => ['in_app', 'android_local'],
                    ],
                ]);
        }

        $this->assertSame(['in_app', 'android_local'], $notification->fresh()->channels);
        $this->assertSame(InAppNotification::STATUS_SENT, $notification->fresh()->status);
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_acknowledgment_refuses_foreign_non_sent_cookie_and_non_mobile_credentials(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $token = $this->issueToken($other);
        $foreign = $this->createDeliveredNotification($owner);
        $read = $this->createDeliveredNotification($other, [
            'source_id' => 20260814,
            'status' => InAppNotification::STATUS_READ,
            'read_at' => now(),
        ]);

        $this->withHeaders($this->bearer($token))
            ->putJson("/api/mobile/notifications/{$foreign->id}/presented")
            ->assertNotFound();
        $this->withHeaders($this->bearer($token))
            ->putJson("/api/mobile/notifications/{$read->id}/presented")
            ->assertUnprocessable();

        $this->withHeaders(['Authorization' => ''])->actingAs($other)
            ->putJson("/api/mobile/notifications/{$read->id}/presented")
            ->assertForbidden();

        $this->assertSame(['in_app'], $foreign->fresh()->channels);
        $this->assertSame(['in_app'], $read->fresh()->channels);
    }
}
