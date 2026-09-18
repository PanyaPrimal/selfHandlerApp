<?php

namespace Tests\Feature\Notifications;

use App\Models\InAppNotification;
use Illuminate\Support\Facades\Artisan;

class NotificationQueueDeliveryTest extends NotificationTestCase
{
    public function test_scheduled_command_needs_a_database_worker_to_deliver_due_reminders(): void
    {
        config(['queue.default' => 'database']);
        $user = $this->createUser();
        $this->createRoutine($user, ['preferred_time' => '08:45']);

        $this->assertSame(0, Artisan::call('notifications:process', ['--user' => $user->id]));
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('notifications', 0);

        $this->assertSame(0, Artisan::call('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--sleep' => 0,
            '--tries' => 1,
        ]));

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => InAppNotification::TYPE_ROUTINE_REMINDER,
            'status' => InAppNotification::STATUS_SENT,
        ]);
    }
}
