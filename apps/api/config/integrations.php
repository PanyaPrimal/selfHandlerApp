<?php

return [
    'sync' => [
        'past_days' => 90,
        'future_days' => 365,
        'timeout_seconds' => 15,
        'connect_timeout_seconds' => 5,
        'max_calendars' => 100,
        'max_events' => 5000,
        'lock_seconds' => 300,
        'due_minutes' => 15,
    ],
    'web_settings_url' => env('SELFHANDLER_WEB_URL', env('APP_URL', 'http://localhost')).'/settings/integrations',
    'google' => [
        'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
        'redirect_uri' => env(
            'GOOGLE_CALENDAR_REDIRECT_URI',
            rtrim(env('APP_URL', 'http://localhost'), '/').'/api/integrations/calendars/google/callback',
        ),
        'authorization_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url' => 'https://oauth2.googleapis.com/token',
        'api_url' => 'https://www.googleapis.com/calendar/v3',
        'scopes' => [
            'https://www.googleapis.com/auth/calendar.events',
            'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
        ],
    ],
    'apple' => [
        'discovery_url' => 'https://caldav.icloud.com/.well-known/caldav',
        'allowed_hosts' => ['caldav.icloud.com', 'p01-caldav.icloud.com', 'p02-caldav.icloud.com',
            'p03-caldav.icloud.com', 'p04-caldav.icloud.com', 'p05-caldav.icloud.com',
            'p06-caldav.icloud.com', 'p07-caldav.icloud.com', 'p08-caldav.icloud.com',
            'p09-caldav.icloud.com', 'p10-caldav.icloud.com', 'p11-caldav.icloud.com',
            'p12-caldav.icloud.com', 'p13-caldav.icloud.com', 'p14-caldav.icloud.com',
            'p15-caldav.icloud.com', 'p16-caldav.icloud.com', 'p17-caldav.icloud.com',
            'p18-caldav.icloud.com', 'p19-caldav.icloud.com', 'p20-caldav.icloud.com'],
    ],
];
