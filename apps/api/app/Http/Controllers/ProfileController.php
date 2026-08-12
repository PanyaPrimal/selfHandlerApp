<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use App\Http\Requests\UpdateThemePreferencesRequest;
use App\Http\Resources\ProfileResource;
use App\Models\UserProfile;
use App\Support\ProfileDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->response($request->user()->ensureProfile(), $request);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $profile = DB::transaction(function () use ($user, $validated): UserProfile {
            $user->forceFill(['name' => $validated['name']])->save();
            unset($validated['name']);

            $profile = $user->ensureProfile();
            $profile->fill($validated)->save();

            return $profile->fresh(['user']);
        });

        return $this->response($profile, $request);
    }

    public function updateTheme(UpdateThemePreferencesRequest $request): JsonResponse
    {
        $profile = $request->user()->ensureProfile();
        $theme = $request->validated('preferences.theme');
        $theme['accent_hex'] = strtolower($theme['accent_hex']);

        $profile->forceFill(['theme_preferences' => $theme])->save();

        return $this->response($profile->fresh(['user']), $request);
    }

    private function response(UserProfile $profile, Request $request): JsonResponse
    {
        $profile->loadMissing('user');

        return response()->json([
            'data' => (new ProfileResource($profile))->resolve($request),
            'options' => ProfileDefaults::options(),
        ]);
    }
}
