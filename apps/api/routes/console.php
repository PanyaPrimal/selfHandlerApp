<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Keep the recurrence window ahead of the user.
 *
 * Feature 006 deferred this to "the first consumer that needs a fresh window";
 * the planner is that consumer, because rescheduling a day attaches to a
 * materialized occurrence, so a day the window has not reached cannot be planned.
 */
Schedule::command('recurrence:materialize')
    ->dailyAt('03:10')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('notifications:process')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('integrations:sync-calendars')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();
