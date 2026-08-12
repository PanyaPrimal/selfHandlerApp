<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePreferencesRequest;
use App\Http\Requests\UpdateProfileRequest;
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

    public function updatePreferences(UpdatePreferencesRequest $request): JsonResponse
    {
        $profile = $request->user()->ensureProfile();
        $preferences = $request->validated('preferences');

        if (array_key_exists('locale', $preferences)) {
            $profile->locale = $preferences['locale'];
        }

        if (array_key_exists('theme', $preferences)) {
            $theme = $preferences['theme'];
            $theme['accent_hex'] = strtolower($theme['accent_hex']);
            $theme['background_hex'] = strtolower($theme['background_hex']);
            $profile->theme_preferences = $theme;
        }

        $profile->save();

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
