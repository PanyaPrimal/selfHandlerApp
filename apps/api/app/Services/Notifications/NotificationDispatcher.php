<?php

namespace App\Services\Notifications;

use App\Models\InAppNotification;
use App\Models\User;
use Carbon\CarbonImmutable;

class NotificationDispatcher
{
    public function __construct(
        private readonly ChannelRegistry $channels,
        private readonly QuietHours $quietHours,
        private readonly NotificationSourceSynchronizer $sources,
    ) {}

    public function dispatchForUser(User $user, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $settings = $user->ensureNotificationSettings();
        $delivered = 0;

        $due = InAppNotification::query()
            ->ownedBy($user)
            ->where(function ($query) use ($now): void {
                $query->where(function ($scheduled) use ($now): void {
                    $scheduled->where('status', InAppNotification::STATUS_SCHEDULED)
                        ->where('scheduled_at', '<=', $now);
                })->orWhere(function ($snoozed) use ($now): void {
                    $snoozed->where('status', InAppNotification::STATUS_SNOOZED)
                        ->where('snoozed_until', '<=', $now);
                });
            })
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get();

        foreach ($due as $notification) {
            if ($notification->source_type !== InAppNotification::SOURCE_DAILY_DIGEST) {
                $disposition = $this->sources->disposition($notification, $user, $now);

                if ($disposition !== 'pending') {
                    $notification->forceFill([
                        'status' => $disposition,
                        'actioned_at' => $disposition === InAppNotification::STATUS_ACTIONED ? $now : null,
                        'cancelled_at' => $disposition === InAppNotification::STATUS_CANCELLED ? $now : null,
                        'next_escalation_at' => null,
                        'snoozed_until' => null,
                    ])->save();

                    continue;
                }
            }

            if (! $settings->categoryEnabled($notification->category)) {
                $notification->forceFill([
                    'status' => InAppNotification::STATUS_CANCELLED,
                    'cancelled_at' => $now,
                    'next_escalation_at' => null,
                ])->save();

                continue;
            }

            $allowedAt = $this->quietHours->nextAllowedAt(
                $now,
                $user->calendarTimezone(),
                $settings->quiet_hours_enabled,
                $settings->quietStartsAt(),
                $settings->quietEndsAt(),
            );

            if ($allowedAt->greaterThan($now)) {
                $changes = ['scheduled_at' => $allowedAt];
                if ($notification->status === InAppNotification::STATUS_SNOOZED) {
                    $changes['snoozed_until'] = $allowedAt;
                }
                $notification->forceFill($changes)->save();

                continue;
            }

            $deliveredChannels = [];
            foreach ((array) config('selfhandler.notifications.channels', ['in_app']) as $key) {
                $channel = $this->channels->get((string) $key);
                $channel->deliver($notification, $user);
                $deliveredChannels[] = $channel->key();
            }

            $canEscalate = $notification->max_escalations > $notification->escalation_count;
            $intervalKey = match ($notification->category) {
                InAppNotification::CATEGORY_HABIT => 'habit',
                InAppNotification::CATEGORY_SUPPLEMENT => 'supplement',
                default => 'routine',
            };
            $interval = (int) config(
                "selfhandler.notifications.{$intervalKey}.escalation_interval_minutes",
                30,
            );

            $notification->forceFill([
                'status' => InAppNotification::STATUS_SENT,
                'channels' => array_values(array_unique($deliveredChannels)),
                'sent_at' => $now,
                'read_at' => null,
                'snoozed_until' => null,
                'next_escalation_at' => $canEscalate ? $now->addMinutes($interval) : null,
            ])->save();
            $delivered++;
        }

        return $delivered;
    }
}
