<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UseRequestLocale
{
    /** @var array<string, string> */
    private const LARAVEL_LOCALES = [
        'en-GB' => 'en',
        'ru-UA' => 'ru',
        'uk-UA' => 'uk',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $profileLocale = $request->user()?->ensureProfile()->locale;
        $requestedLocale = $this->requestedLocale($request);
        $locale = is_string($profileLocale) && isset(self::LARAVEL_LOCALES[$profileLocale])
            ? $profileLocale
            : $requestedLocale;

        app()->setLocale(self::LARAVEL_LOCALES[$locale] ?? 'en');

        return $next($request);
    }

    private function requestedLocale(Request $request): string
    {
        foreach ($request->getLanguages() as $language) {
            $normalized = str_replace('_', '-', $language);

            foreach (array_keys(self::LARAVEL_LOCALES) as $supported) {
                if (strcasecmp($normalized, $supported) === 0
                    || strcasecmp(substr($normalized, 0, 2), substr($supported, 0, 2)) === 0) {
                    return $supported;
                }
            }
        }

        return 'en-GB';
    }
}
