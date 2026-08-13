<?php

namespace App\Providers;

use App\Contracts\NotificationChannel;
use App\Models\Attachment;
use App\Models\BodyMeasurement;
use App\Models\Meal;
use App\Models\User;
use App\Observers\BodyMeasurementObserver;
use App\Observers\MealObserver;
use App\Observers\UserAttachmentObserver;
use App\Services\Notifications\InAppChannel;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(NotificationChannel::class, InAppChannel::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Outside production, a write that names an attribute the model does not
        // accept is a bug worth failing on instead of dropping it in silence.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Relation::morphMap(Attachment::parentClasses());
        BodyMeasurement::observe(BodyMeasurementObserver::class);
        Meal::observe(MealObserver::class);
        User::observe(UserAttachmentObserver::class);

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
