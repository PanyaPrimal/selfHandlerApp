<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RequireMobileToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->bearerToken();

        if ($plainTextToken === null) {
            abort(403, __('messages.mobile_token_required'));
        }

        $token = PersonalAccessToken::findToken($plainTextToken);

        // Sanctum can authenticate the browser cookie before inspecting the
        // bearer. Recheck its lifetime here before attaching it to that user.
        $expiration = config('sanctum.expiration');
        if ($token === null
            || ($token->expires_at !== null && $token->expires_at->isPast())
            || ($expiration && ! $token->created_at->gt(now()->subMinutes((int) $expiration)))) {
            abort(401, __('messages.unauthenticated'));
        }

        $ability = (string) config('selfhandler.mobile.ability', 'mobile');

        abort_unless(
            $token instanceof PersonalAccessToken
                && $request->user()?->is($token->tokenable)
                && $token->abilities === [$ability]
                && $token->can($ability),
            403,
            __('messages.mobile_token_required'),
        );

        $request->user()->withAccessToken($token);

        return $next($request);
    }
}
