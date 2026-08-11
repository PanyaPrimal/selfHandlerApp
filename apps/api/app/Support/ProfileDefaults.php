<?php

namespace App\Support;

final class ProfileDefaults
{
    /** @return array<string, mixed> */
    public static function attributes(): array
    {
        return [
            'timezone' => (string) config('selfhandler.timezone', 'UTC'),
            'locale' => (string) config('selfhandler.profile.defaults.locale', 'en-GB'),
            'unit_system' => (string) config('selfhandler.profile.defaults.unit_system', 'metric'),
            'base_currency' => (string) config('selfhandler.profile.defaults.base_currency', 'UAH'),
            'recommendation_tone' => (string) config('selfhandler.profile.defaults.recommendation_tone', 'neutral'),
            'bmr_formula' => (string) config('selfhandler.profile.defaults.bmr_formula', 'mifflin_st_jeor'),
        ];
    }

    /** @return array<string, list<string>> */
    public static function options(): array
    {
        return [
            'timezones' => timezone_identifiers_list(),
            'locales' => config('selfhandler.profile.locales', []),
            'unit_systems' => config('selfhandler.profile.unit_systems', []),
            'base_currencies' => config('selfhandler.profile.currencies', []),
            'recommendation_tones' => config('selfhandler.profile.recommendation_tones', []),
            'bmr_formulas' => config('selfhandler.profile.bmr_formulas', []),
            'sexes' => config('selfhandler.profile.sexes', []),
            'baseline_activities' => config('selfhandler.profile.baseline_activities', []),
        ];
    }
}
