<?php

namespace App\Services\Notifications;

use App\Contracts\NotificationChannel;
use App\Models\InAppNotification;
use App\Models\User;
use Illuminate\Support\Facades\App;
use RuntimeException;

class InAppChannel implements NotificationChannel
{
    public function key(): string
    {
        return InAppNotification::CHANNEL_IN_APP;
    }

    public function deliver(InAppNotification $notification, User $recipient): void
    {
        $previous = App::getLocale();
        App::setLocale($this->applicationLocale($recipient->ensureProfile()->locale));

        try {
            [$titleKey, $bodyKey] = $this->messageKeys($notification);
            $parameters = $notification->content ?? [];

            $notification->forceFill([
                'title' => __($titleKey, $parameters),
                'body' => __($bodyKey, $parameters),
            ]);
        } finally {
            App::setLocale($previous);
        }
    }

    /** @return array{string, string} */
    private function messageKeys(InAppNotification $notification): array
    {
        return match ($notification->type) {
            InAppNotification::TYPE_ROUTINE_REMINDER => $notification->escalation_count > 0
                ? ['notifications.routine_escalation_title', 'notifications.routine_escalation_body']
                : ['notifications.routine_title', 'notifications.routine_body'],
            InAppNotification::TYPE_HABIT_REMINDER => $notification->escalation_count > 0
                ? ['notifications.habit_escalation_title', 'notifications.habit_escalation_body']
                : ['notifications.habit_title', 'notifications.habit_body'],
            InAppNotification::TYPE_SLEEP_REMINDER => $notification->escalation_count > 0
                ? ['notifications.sleep_escalation_title', 'notifications.sleep_escalation_body']
                : ['notifications.sleep_title', 'notifications.sleep_body'],
            InAppNotification::TYPE_WORKOUT_REMINDER => $notification->escalation_count > 0
                ? ['notifications.workout_escalation_title', 'notifications.workout_escalation_body']
                : ['notifications.workout_title', 'notifications.workout_body'],
            InAppNotification::TYPE_STORAGE_DUE => [
                'notifications.storage_title', 'notifications.storage_body',
            ],
            InAppNotification::TYPE_DAILY_DIGEST => [
                'notifications.digest_title', 'notifications.digest_body',
            ],
            default => throw new RuntimeException("Unsupported notification type: {$notification->type}"),
        };
    }

    private function applicationLocale(string $profileLocale): string
    {
        return match ($profileLocale) {
            'ru-UA' => 'ru',
            'uk-UA' => 'uk',
            default => 'en',
        };
    }
}
