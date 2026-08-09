<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::getAccessTokenFromRequestUsing(static fn (Request $request): ?string => null);

        RateLimiter::for('login', static function (Request $request): Limit {
            return Limit::perMinute(60)
                ->by($request->ip() ?? 'unknown')
                ->response(static fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many login attempts. Please try again later.',
                ], 429, $headers));
        });

        RateLimiter::for('registration', static function (Request $request): Limit {
            return Limit::perMinute(max(1, (int) config('auth.registration_attempts_per_minute', 3)))
                ->by($request->ip() ?? 'unknown')
                ->response(static fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many registration attempts. Please try again later.',
                ], 429, $headers));
        });
    }
}
