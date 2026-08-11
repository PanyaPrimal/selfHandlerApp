<?php

namespace App\Http\Controllers;

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

    private function response(UserProfile $profile, Request $request): JsonResponse
    {
        $profile->loadMissing('user');

        return response()->json([
            'data' => (new ProfileResource($profile))->resolve($request),
            'options' => ProfileDefaults::options(),
        ]);
    }
}
