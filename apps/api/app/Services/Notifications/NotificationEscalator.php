<?php

namespace App\Services\Notifications;

use App\Models\InAppNotification;
use App\Models\User;
use Carbon\CarbonImmutable;

class NotificationEscalator
{
    public function __construct(private readonly NotificationSourceSynchronizer $sources) {}

    public function scheduleForUser(User $user, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $settings = $user->ensureNotificationSettings();
        $created = 0;

        $due = InAppNotification::query()
            ->ownedBy($user)
            ->whereIn('status', InAppNotification::VISIBLE_STATUSES)
            ->whereNotNull('next_escalation_at')
            ->where('next_escalation_at', '<=', $now)
            ->whereColumn('escalation_count', '<', 'max_escalations')
            ->orderBy('id')
            ->get();

        foreach ($due as $notification) {
            $notification->forceFill(['next_escalation_at' => null])->save();

            if (! $settings->categoryEnabled($notification->category)
                || $this->sources->disposition($notification, $user, $now) !== 'pending'
                || $this->familyWasDismissed($notification)) {
                continue;
            }

            $nextCount = $notification->escalation_count + 1;
            $repeat = InAppNotification::query()->firstOrCreate([
                'user_id' => $user->id,
                'source_type' => $notification->source_type,
                'source_id' => $notification->source_id,
                'escalation_count' => $nextCount,
            ], [
                'type' => $notification->type,
                'category' => $notification->category,
                'title' => null,
                'body' => null,
                'action_url' => $notification->action_url,
                'content' => $notification->content,
                'scheduled_at' => $now,
                'status' => InAppNotification::STATUS_SCHEDULED,
                'channels' => [],
                'max_escalations' => $notification->max_escalations,
            ]);

            if ($repeat->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    private function familyWasDismissed(InAppNotification $notification): bool
    {
        return InAppNotification::query()
            ->where('user_id', $notification->user_id)
            ->where('source_type', $notification->source_type)
            ->where('source_id', $notification->source_id)
            ->where('status', InAppNotification::STATUS_DISMISSED)
            ->exists();
    }
}
