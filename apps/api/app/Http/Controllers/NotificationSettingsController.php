<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReplaceNotificationSettingsRequest;
use App\Models\NotificationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->response($request->user()->ensureNotificationSettings());
    }

    public function replace(ReplaceNotificationSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $settings = DB::transaction(function () use ($request, $data): NotificationSettings {
            $settings = $request->user()->ensureNotificationSettings();
            $settings->fill([
                'quiet_hours_enabled' => $data['quiet_hours']['enabled'],
                'quiet_starts_at' => $data['quiet_hours']['starts_at'],
                'quiet_ends_at' => $data['quiet_hours']['ends_at'],
                'digest_enabled' => $data['digest']['enabled'],
                'digest_time' => $data['digest']['time'],
                'categories' => $data['categories'],
            ])->save();

            return $settings->fresh();
        });

        return $this->response($settings);
    }

    private function response(NotificationSettings $settings): JsonResponse
    {
        return response()->json([
            'data' => $settings->toApi(),
            'options' => [
                'categories' => NotificationSettings::CATEGORIES,
                'channels' => NotificationSettings::CHANNELS,
                'snooze_minutes' => NotificationSettings::SNOOZE_MINUTES,
            ],
        ]);
    }
}
