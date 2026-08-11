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

    'profile' => [
        'locales' => ['en-GB', 'uk-UA', 'ru-UA'],
        'unit_systems' => ['metric', 'imperial'],
        'currencies' => ['UAH', 'USD', 'EUR'],
        'recommendation_tones' => ['neutral', 'friendly', 'direct'],
        'bmr_formulas' => ['mifflin_st_jeor', 'katch_mcardle'],
        'sexes' => ['female', 'male', 'unspecified'],
        'baseline_activities' => ['sedentary', 'light', 'moderate', 'high'],
        'defaults' => [
            'locale' => 'en-GB',
            'unit_system' => 'metric',
            'base_currency' => 'UAH',
            'recommendation_tone' => 'neutral',
            'bmr_formula' => 'mifflin_st_jeor',
        ],
    ],

];
