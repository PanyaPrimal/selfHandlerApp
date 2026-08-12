<?php

namespace Tests\Feature\Planner;

use Illuminate\Support\Facades\Schedule;

class MaterializationScheduleTest extends PlannerTestCase
{
    public function test_the_materialization_window_is_kept_ahead_on_a_schedule(): void
    {
        $events = collect(Schedule::events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'recurrence:materialize'));

        // Without a scheduled run the window silently stops advancing and a
        // future day quietly becomes unplannable.
        $this->assertCount(1, $events, 'recurrence:materialize must be registered on the schedule.');

        $event = $events->first();

        // Overlapping runs would fight over the same upsert, and on more than
        // one host the window would be rebuilt several times a night.
        $this->assertNotNull($event->withoutOverlapping, 'The run must not overlap itself.');
        $this->assertTrue($event->onOneServer, 'The run must happen on one server only.');
    }
}
