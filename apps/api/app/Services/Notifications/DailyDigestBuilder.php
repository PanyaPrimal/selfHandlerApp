<?php

namespace App\Services\Notifications;

use App\Models\InAppNotification;
use App\Models\Item;
use App\Models\PlannedOccurrence;
use App\Models\User;
use Carbon\CarbonImmutable;

class DailyDigestBuilder
{
    public function __construct(private readonly NotificationSourceSynchronizer $sources) {}

    public function build(User $user, ?CarbonImmutable $now = null): ?InAppNotification
    {
        $now ??= CarbonImmutable::now();
        $settings = $user->ensureNotificationSettings();

        if (! $settings->digest_enabled) {
            return null;
        }

        $timezone = $user->calendarTimezone();
        $localNow = $now->setTimezone($timezone);
        $date = $localNow->toDateString();
        $scheduledAt = CarbonImmutable::parse("{$date} {$settings->digestTime()}", $timezone);

        if ($localNow->lessThan($scheduledAt)) {
            return null;
        }

        $routineCount = $settings->categoryEnabled(InAppNotification::CATEGORY_ROUTINE)
            ? $this->untimedRoutineCount($user, $date)
            : 0;
        $storageCount = $settings->categoryEnabled(InAppNotification::CATEGORY_STORAGE)
            ? $this->minorStorageCount($user, $date)
            : 0;
        $total = $routineCount + $storageCount;

        if ($total === 0) {
            return null;
        }

        $notification = InAppNotification::query()->firstOrNew([
            'user_id' => $user->id,
            'source_type' => InAppNotification::SOURCE_DAILY_DIGEST,
            'source_id' => (int) str_replace('-', '', $date),
            'escalation_count' => 0,
        ]);

        if (! $notification->exists || $notification->status === InAppNotification::STATUS_SCHEDULED) {
            $notification->fill([
                'user_id' => $user->id,
                'type' => InAppNotification::TYPE_DAILY_DIGEST,
                'category' => InAppNotification::CATEGORY_DIGEST,
                'title' => null,
                'body' => null,
                'action_url' => "/planner?date={$date}",
                'content' => [
                    'date' => $date,
                    'total' => $total,
                    'routine_count' => $routineCount,
                    'storage_count' => $storageCount,
                ],
                'scheduled_at' => $scheduledAt->utc(),
                'status' => InAppNotification::STATUS_SCHEDULED,
                'channels' => [],
                'max_escalations' => 0,
            ]);
            $notification->save();
        }

        return $notification;
    }

    private function untimedRoutineCount(User $user, string $date): int
    {
        $routines = $this->sources->activeRoutines($user);

        return PlannedOccurrence::query()
            ->ownedBy($user)
            ->with('recurringRule')
            ->where('status', PlannedOccurrence::STATUS_PLANNED)
            ->whereNull('occurrence_time')
            ->get()
            ->filter(fn (PlannedOccurrence $occurrence): bool => $this->sources->effectiveDate($occurrence) === $date
                && $this->sources->routineForOccurrence($occurrence, $routines) !== null)
            ->count();
    }

    private function minorStorageCount(User $user, string $date): int
    {
        return Item::query()
            ->ownedBy($user)
            ->where('type', Item::TYPE_TASK)
            ->whereIn('status', Item::OPEN_STATUSES)
            ->where('due_on', $date)
            ->where(function ($query): void {
                $query->whereNull('priority')->orWhere('priority', '!=', 'high');
            })
            ->count();
    }
}
