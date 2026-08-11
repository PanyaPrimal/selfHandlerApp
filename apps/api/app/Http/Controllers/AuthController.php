<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $code = $request->validated('invite_code');

        try {
            $user = DB::transaction(function () use ($request, $code): User {
                // Lock the invite row so two concurrent sign-ups cannot both
                // consume the same code; re-check it is still unused inside the
                // transaction.
                $invitation = Invitation::where('code', $code)
                    ->whereNull('used_at')
                    ->lockForUpdate()
                    ->first();

                if ($invitation === null) {
                    throw ValidationException::withMessages([
                        'invite_code' => ['This invite code is invalid or has already been used.'],
                    ]);
                }

                $user = User::create($request->safe()->only(['name', 'email', 'password']));
                $user->ensureProfile();

                $invitation->forceFill([
                    'used_by' => $user->id,
                    'used_at' => now(),
                ])->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => ['The email has already been taken.'],
            ]);
        }

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
