<?php

use App\Http\Middleware\RequireMobileToken;
use App\Http\Middleware\UseRequestLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // PHP-FPM is reachable only from the isolated Nginx network. Nginx
        // overwrites these headers, so Laravel can safely recover the fixed
        // private HTTPS origin instead of trusting client-supplied values.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->statefulApi();
        $middleware->alias(['mobile.token' => RequireMobileToken::class]);
        $middleware->appendToGroup('api', UseRequestLocale::class);
        $middleware->appendToGroup('web', UseRequestLocale::class);
        $middleware->redirectGuestsTo(
            static fn (Request $request): ?string => $request->is('api/*') ? null : '/login',
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
