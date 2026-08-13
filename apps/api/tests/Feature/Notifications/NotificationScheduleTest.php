<?php

namespace Tests\Feature\Notifications;

use App\Jobs\ProcessUserNotifications;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;

class NotificationScheduleTest extends NotificationTestCase
{
    public function test_the_command_queues_one_user_job_and_sync_mode_runs_it_inline(): void
    {
        $owner = $this->createUser();
        Queue::fake();

        $this->artisan('notifications:process')->assertSuccessful();
        Queue::assertPushed(ProcessUserNotifications::class, 1);
        Queue::assertPushed(fn (ProcessUserNotifications $job): bool => $job->userId === $owner->id);

        Queue::fake();
        $this->artisan('notifications:process', ['--user' => $owner->id, '--sync' => true])
            ->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_processing_is_registered_every_minute_without_overlap_on_one_server(): void
    {
        $events = collect(Schedule::events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'notifications:process'));

        $this->assertCount(1, $events);
        $event = $events->first();

        $this->assertSame('* * * * *', $event->expression);
        $this->assertNotNull($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }
}
