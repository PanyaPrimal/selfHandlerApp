<?php

namespace App\Http\Controllers;

use App\Models\InAppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MobileNotificationController extends Controller
{
    public function presented(Request $request, InAppNotification $notification): JsonResponse
    {
        abort_unless($notification->isOwnedBy($request->user()), 404);

        if ($notification->status !== InAppNotification::STATUS_SENT) {
            throw ValidationException::withMessages([
                'notification' => __('messages.mobile_presentation_state'),
            ]);
        }

        $channels = array_values(array_unique([
            ...($notification->channels ?? []),
            InAppNotification::CHANNEL_ANDROID_LOCAL,
        ]));

        if ($channels !== $notification->channels) {
            $notification->forceFill(['channels' => $channels])->save();
        }

        return response()->json([
            'data' => [
                'id' => $notification->id,
                'status' => $notification->status,
                'channels' => $notification->channels,
            ],
        ]);
    }
}
