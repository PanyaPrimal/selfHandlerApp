<?php

namespace Tests\Feature\Integrations;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarIntegrationScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_sync_command_is_idempotent_when_nothing_is_due(): void
    {
        $this->artisan('integrations:sync-calendars')->assertSuccessful();
    }

    public function test_calendar_polling_is_frequent_non_overlapping_and_single_server(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($candidate): bool => str_contains($candidate->command ?? '', 'integrations:sync-calendars'));

        $this->assertNotNull($event);
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertNotNull($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }
}
