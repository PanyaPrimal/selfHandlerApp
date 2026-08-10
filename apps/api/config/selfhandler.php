<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Calendar Timezone
    |--------------------------------------------------------------------------
    |
    | Storage and `APP_TIMEZONE` stay on UTC. This is the separate calendar
    | timezone that decides which calendar day a routine log, daily review, or
    | Today request belongs to. Feature 001 uses one configured value for the
    | whole installation; once the Profile module exists, the per-user setting
    | becomes the primary source and this value stays as the fallback.
    |
    */

    'timezone' => env('SELFHANDLER_TIMEZONE', 'UTC'),

];
