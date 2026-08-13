<?php

namespace App\Http\Controllers;

use App\Http\Requests\MobileLoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class MobileSessionController extends Controller
{
    public function store(MobileLoginRequest $request): JsonResponse
    {
        $user = $request->authenticate();
        $expiresAt = now()->addDays((int) config('selfhandler.mobile.token_lifetime_days', 30));
        $ability = (string) config('selfhandler.mobile.ability', 'mobile');
        $name = (string) config('selfhandler.mobile.token_name_prefix', 'Android · ')
            .$request->string('device_name')->toString();
        $token = $user->createToken($name, [$ability], $expiresAt);

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt->toISOString(),
                'user' => (new UserResource($user))->resolve($request),
            ],
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        /** @var PersonalAccessToken $token */
        $token = $request->user()->currentAccessToken();

        return response()->json([
            'data' => [
                'expires_at' => $token->expires_at?->toISOString(),
                'user' => (new UserResource($request->user()))->resolve($request),
            ],
        ]);
    }

    public function destroy(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
