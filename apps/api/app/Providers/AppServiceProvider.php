<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
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

        // Outside production, a write that names an attribute the model does not
        // accept is a bug worth failing on instead of dropping it in silence.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        RateLimiter::for('login', static function (Request $request): Limit {
            return Limit::perMinute(60)
                ->by($request->ip() ?? 'unknown')
                ->response(static fn (Request $request, array $headers) => response()->json([
                    'message' => __('messages.too_many_login'),
                ], 429, $headers));
        });

        RateLimiter::for('registration', static function (Request $request): Limit {
            return Limit::perMinute(max(1, (int) config('auth.registration_attempts_per_minute', 3)))
                ->by($request->ip() ?? 'unknown')
                ->response(static fn (Request $request, array $headers) => response()->json([
                    'message' => __('messages.too_many_registration'),
                ], 429, $headers));
        });
    }
}
