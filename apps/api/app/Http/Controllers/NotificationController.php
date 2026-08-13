<?php

namespace App\Http\Controllers;

use App\Models\InAppNotification;
use App\Models\NotificationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->validate([
            'view' => ['sometimes', Rule::in(['all', 'unread'])],
        ]);
        $view = $filters['view'] ?? 'all';

        $notifications = InAppNotification::query()
            ->ownedBy($user)
            ->whereIn('status', $view === 'unread'
                ? [InAppNotification::STATUS_SENT]
                : InAppNotification::VISIBLE_STATUSES)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $notifications->map->toApi()->values(),
            'unread_count' => $this->unreadCount($user->id),
            'views' => ['all', 'unread'],
            'snooze_options' => NotificationSettings::SNOOZE_MINUTES,
        ]);
    }

    public function read(Request $request, InAppNotification $notification): JsonResponse
    {
        $this->assertOwned($request, $notification);

        if ($notification->status !== InAppNotification::STATUS_READ) {
            $this->assertState($notification, [InAppNotification::STATUS_SENT]);
            $notification->forceFill([
                'status' => InAppNotification::STATUS_READ,
                'read_at' => now(),
            ])->save();
        }

        return response()->json([
            'data' => $notification->fresh()->toApi(),
            'unread_count' => $this->unreadCount($request->user()->id),
        ]);
    }

    public function dismiss(Request $request, InAppNotification $notification): Response
    {
        $this->assertOwned($request, $notification);

        if ($notification->status === InAppNotification::STATUS_DISMISSED) {
            return response()->noContent();
        }

        $this->assertState($notification, [
            InAppNotification::STATUS_SENT,
            InAppNotification::STATUS_READ,
            InAppNotification::STATUS_SNOOZED,
        ]);

        DB::transaction(function () use ($notification): void {
            InAppNotification::query()
                ->where('user_id', $notification->user_id)
                ->where('source_type', $notification->source_type)
                ->where('source_id', $notification->source_id)
                ->whereKeyNot($notification->id)
                ->whereIn('status', InAppNotification::ACTIVE_STATUSES)
                ->update([
                    'status' => InAppNotification::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'next_escalation_at' => null,
                    'snoozed_until' => null,
                    'updated_at' => now(),
                ]);

            $notification->forceFill([
                'status' => InAppNotification::STATUS_DISMISSED,
                'dismissed_at' => now(),
                'next_escalation_at' => null,
                'snoozed_until' => null,
            ])->save();
        });

        return response()->noContent();
    }

    public function snooze(Request $request, InAppNotification $notification): JsonResponse
    {
        $this->assertOwned($request, $notification);
        $data = $request->validate([
            'minutes' => ['required', 'integer', Rule::in(NotificationSettings::SNOOZE_MINUTES)],
        ]);
        $this->assertState($notification, [
            InAppNotification::STATUS_SENT,
            InAppNotification::STATUS_READ,
        ]);

        $until = now()->addMinutes((int) $data['minutes']);
        $notification->forceFill([
            'status' => InAppNotification::STATUS_SNOOZED,
            'scheduled_at' => $until,
            'snoozed_until' => $until,
            'next_escalation_at' => null,
        ])->save();

        return response()->json([
            'data' => [
                'id' => $notification->id,
                'status' => $notification->status,
                'snoozed_until' => $notification->snoozed_until->toISOString(),
            ],
        ]);
    }

    private function assertOwned(Request $request, InAppNotification $notification): void
    {
        abort_unless($notification->isOwnedBy($request->user()), 404);
    }

    /** @param list<string> $allowed */
    private function assertState(InAppNotification $notification, array $allowed): void
    {
        if (! in_array($notification->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'notification' => __('messages.notification_transition'),
            ]);
        }
    }

    private function unreadCount(int $userId): int
    {
        return InAppNotification::query()
            ->ownedBy($userId)
            ->where('status', InAppNotification::STATUS_SENT)
            ->count();
    }
}
