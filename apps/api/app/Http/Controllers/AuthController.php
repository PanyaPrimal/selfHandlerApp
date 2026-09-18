<?php

namespace App\Http\Controllers;

use App\Actions\RegisterAccount;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegisterAccount $accounts): JsonResponse
    {
        $user = $accounts->create($request->safe()->only(['name', 'email', 'password']));

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        $user = Auth::guard('web')->user();
        $user->ensureProfile();

        return (new UserResource($user))->response();
    }

    public function user(Request $request): JsonResponse
    {
        $request->user()->ensureProfile();

        return (new UserResource($request->user()))
            ->response()
            ->setStatusCode(200);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
