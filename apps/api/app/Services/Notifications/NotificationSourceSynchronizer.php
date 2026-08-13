<?php

namespace App\Services\Notifications;

use App\Models\Habit;
use App\Models\InAppNotification;
use App\Models\Item;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\Routine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class NotificationSourceSynchronizer
{
    /**
     * Synchronize direct source records and close stale delivery state.
     *
     * @return int number of newly scheduled/re-armed initial records
     */
    public function synchronize(User $user, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $settings = $user->ensureNotificationSettings();
        $timezone = $user->calendarTimezone();
        $today = $now->setTimezone($timezone)->toDateString();
        $written = 0;

        $routines = $this->activeRoutines($user);
        $habits = $this->activeHabits($user);
        $occurrences = PlannedOccurrence::query()
            ->ownedBy($user)
            ->with('recurringRule')
            ->get();
        $items = Item::query()->ownedBy($user)->get()->keyBy('id');

        $this->closeInvalidExisting(
            $user,
            $occurrences->keyBy('id'),
            $items,
            $routines,
            $habits,
            $today,
        );

        if ($settings->categoryEnabled(InAppNotification::CATEGORY_ROUTINE)) {
            foreach ($occurrences as $occurrence) {
                $routine = $this->routineForOccurrence($occurrence, $routines);
                $effectiveDate = $this->effectiveDate($occurrence);

                if (! $routine
                    || $occurrence->status !== PlannedOccurrence::STATUS_PLANNED
                    || blank($occurrence->occurrence_time)
                    || $effectiveDate !== $today) {
                    continue;
                }

                $scheduledAt = CarbonImmutable::parse(
                    "{$effectiveDate} ".substr((string) $occurrence->occurrence_time, 0, 8),
                    $timezone,
                )->utc();

                $written += $this->upsertInitial($user, [
                    'source_type' => InAppNotification::SOURCE_PLANNED_OCCURRENCE,
                    'source_id' => $occurrence->id,
                    'type' => InAppNotification::TYPE_ROUTINE_REMINDER,
                    'category' => InAppNotification::CATEGORY_ROUTINE,
                    'content' => ['title' => $routine->name, 'date' => $effectiveDate],
                    'action_url' => "/planner?date={$effectiveDate}",
                    'scheduled_at' => $scheduledAt,
                    'max_escalations' => (int) config('selfhandler.notifications.routine.max_escalations', 2),
                ]);
            }
        }

        if ($settings->categoryEnabled(InAppNotification::CATEGORY_HABIT)) {
            foreach ($occurrences as $occurrence) {
                $habit = $this->habitForOccurrence($occurrence, $habits);
                $effectiveDate = $this->effectiveDate($occurrence);

                if (! $habit
                    || $occurrence->status !== PlannedOccurrence::STATUS_PLANNED
                    || blank($occurrence->occurrence_time)
                    || $effectiveDate !== $today) {
                    continue;
                }

                $scheduledAt = CarbonImmutable::parse(
                    "{$effectiveDate} ".substr((string) $occurrence->occurrence_time, 0, 8),
                    $timezone,
                )->utc();

                $written += $this->upsertInitial($user, [
                    'source_type' => InAppNotification::SOURCE_PLANNED_OCCURRENCE,
                    'source_id' => $occurrence->id,
                    'type' => InAppNotification::TYPE_HABIT_REMINDER,
                    'category' => InAppNotification::CATEGORY_HABIT,
                    'content' => ['title' => $habit->name, 'date' => $effectiveDate],
                    'action_url' => "/planner?date={$effectiveDate}",
                    'scheduled_at' => $scheduledAt,
                    'max_escalations' => (int) config('selfhandler.notifications.habit.max_escalations', 2),
                ]);
            }
        }

        if ($settings->categoryEnabled(InAppNotification::CATEGORY_STORAGE)) {
            $digestAt = CarbonImmutable::parse("{$today} {$settings->digestTime()}", $timezone)->utc();

            foreach ($items as $item) {
                if (! $this->isDirectStorageItem($item, $today)) {
                    continue;
                }

                $written += $this->upsertInitial($user, [
                    'source_type' => InAppNotification::SOURCE_STORAGE_ITEM,
                    'source_id' => $item->id,
                    'type' => InAppNotification::TYPE_STORAGE_DUE,
                    'category' => InAppNotification::CATEGORY_STORAGE,
                    'content' => ['title' => $item->title, 'date' => $today],
                    'action_url' => "/planner?date={$today}",
                    'scheduled_at' => $digestAt,
                    'max_escalations' => 0,
                ]);
            }
        }

        return $written;
    }

    /** `pending`, `actioned`, or `cancelled` for a source-backed record. */
    public function disposition(InAppNotification $notification, User $user, CarbonImmutable $now): string
    {
        $today = $now->setTimezone($user->calendarTimezone())->toDateString();

        if ($notification->source_type === InAppNotification::SOURCE_PLANNED_OCCURRENCE) {
            $occurrence = PlannedOccurrence::query()
                ->ownedBy($user)
                ->with('recurringRule')
                ->find($notification->source_id);

            if (! $occurrence) {
                return InAppNotification::STATUS_CANCELLED;
            }

            if ($occurrence->status === PlannedOccurrence::STATUS_DONE) {
                return InAppNotification::STATUS_ACTIONED;
            }

            $activeOwner = match ($occurrence->recurringRule?->owner_type) {
                RecurringRule::OWNER_ROUTINE => Routine::query()
                    ->ownedBy($user)
                    ->whereKey($occurrence->recurringRule->owner_id)
                    ->where('is_active', true)
                    ->where('is_archived', false)
                    ->exists(),
                RecurringRule::OWNER_HABIT => Habit::query()
                    ->ownedBy($user)
                    ->whereKey($occurrence->recurringRule->owner_id)
                    ->where('is_active', true)
                    ->where('is_archived', false)
                    ->exists(),
                default => false,
            };

            return $occurrence->status === PlannedOccurrence::STATUS_PLANNED
                && $activeOwner
                && filled($occurrence->occurrence_time)
                && $this->effectiveDate($occurrence) === $today
                    ? 'pending'
                    : InAppNotification::STATUS_CANCELLED;
        }

        if ($notification->source_type === InAppNotification::SOURCE_STORAGE_ITEM) {
            $item = Item::query()->ownedBy($user)->find($notification->source_id);

            if ($item?->status === Item::STATUS_DONE) {
                return InAppNotification::STATUS_ACTIONED;
            }

            return $item && $this->isDirectStorageItem($item, $today)
                ? 'pending'
                : InAppNotification::STATUS_CANCELLED;
        }

        return 'pending';
    }

    /** @return Collection<int, Routine> */
    public function activeRoutines(User $user): Collection
    {
        return Routine::query()
            ->ownedBy($user)
            ->where('is_active', true)
            ->where('is_archived', false)
            ->get()
            ->keyBy('id');
    }

    public function routineForOccurrence(PlannedOccurrence $occurrence, Collection $routines): ?Routine
    {
        $rule = $occurrence->recurringRule;

        if (! $rule || $rule->owner_type !== RecurringRule::OWNER_ROUTINE) {
            return null;
        }

        return $routines->get($rule->owner_id);
    }

    /** @return Collection<int, Habit> */
    public function activeHabits(User $user): Collection
    {
        return Habit::query()
            ->ownedBy($user)
            ->where('is_active', true)
            ->where('is_archived', false)
            ->get()
            ->keyBy('id');
    }

    public function habitForOccurrence(PlannedOccurrence $occurrence, Collection $habits): ?Habit
    {
        $rule = $occurrence->recurringRule;

        if (! $rule || $rule->owner_type !== RecurringRule::OWNER_HABIT) {
            return null;
        }

        return $habits->get($rule->owner_id);
    }

    public function effectiveDate(PlannedOccurrence $occurrence): string
    {
        return ($occurrence->rescheduled_to ?? $occurrence->occurrence_date)->format('Y-m-d');
    }

    private function isDirectStorageItem(Item $item, string $today): bool
    {
        return $item->type === Item::TYPE_TASK
            && $item->isOpen()
            && $item->priority === 'high'
            && $item->due_on?->format('Y-m-d') === $today;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertInitial(User $user, array $attributes): int
    {
        $notification = InAppNotification::query()->firstOrNew([
            'user_id' => $user->id,
            'source_type' => $attributes['source_type'],
            'source_id' => $attributes['source_id'],
            'escalation_count' => 0,
        ]);

        if ($notification->exists && $notification->status === InAppNotification::STATUS_DISMISSED) {
            return 0;
        }

        if ($notification->exists && in_array($notification->status, [
            InAppNotification::STATUS_SENT,
            InAppNotification::STATUS_READ,
            InAppNotification::STATUS_SNOOZED,
        ], true)) {
            return 0;
        }

        $isNewOrRearmed = ! $notification->exists || in_array($notification->status, [
            InAppNotification::STATUS_ACTIONED,
            InAppNotification::STATUS_CANCELLED,
        ], true);

        $notification->fill([
            ...$attributes,
            'user_id' => $user->id,
            'status' => InAppNotification::STATUS_SCHEDULED,
            'channels' => [],
            'title' => null,
            'body' => null,
            'next_escalation_at' => null,
            'snoozed_until' => null,
            'sent_at' => null,
            'read_at' => null,
            'dismissed_at' => null,
            'actioned_at' => null,
            'cancelled_at' => null,
        ]);
        $notification->save();

        return $isNewOrRearmed ? 1 : 0;
    }

    /**
     * @param  Collection<int, PlannedOccurrence>  $occurrences
     * @param  Collection<int, Item>  $items
     * @param  Collection<int, Routine>  $routines
     * @param  Collection<int, Habit>  $habits
     */
    private function closeInvalidExisting(
        User $user,
        Collection $occurrences,
        Collection $items,
        Collection $routines,
        Collection $habits,
        string $today,
    ): void {
        $settings = $user->ensureNotificationSettings();

        InAppNotification::query()
            ->ownedBy($user)
            ->whereIn('source_type', [
                InAppNotification::SOURCE_PLANNED_OCCURRENCE,
                InAppNotification::SOURCE_STORAGE_ITEM,
            ])
            ->whereIn('status', InAppNotification::ACTIVE_STATUSES)
            ->get()
            ->each(function (InAppNotification $notification) use (
                $settings,
                $occurrences,
                $items,
                $routines,
                $habits,
                $today,
            ): void {
                $terminal = null;

                if (! $settings->categoryEnabled($notification->category)) {
                    $terminal = InAppNotification::STATUS_CANCELLED;
                } elseif ($notification->source_type === InAppNotification::SOURCE_PLANNED_OCCURRENCE) {
                    $occurrence = $occurrences->get($notification->source_id);

                    if ($occurrence?->status === PlannedOccurrence::STATUS_DONE) {
                        $terminal = InAppNotification::STATUS_ACTIONED;
                    } elseif (! $occurrence
                        || $occurrence->status !== PlannedOccurrence::STATUS_PLANNED
                        || (! $this->routineForOccurrence($occurrence, $routines)
                            && ! $this->habitForOccurrence($occurrence, $habits))
                        || blank($occurrence->occurrence_time)
                        || $this->effectiveDate($occurrence) !== $today) {
                        $terminal = InAppNotification::STATUS_CANCELLED;
                    }
                } else {
                    $item = $items->get($notification->source_id);

                    if ($item?->status === Item::STATUS_DONE) {
                        $terminal = InAppNotification::STATUS_ACTIONED;
                    } elseif (! $item || ! $this->isDirectStorageItem($item, $today)) {
                        $terminal = InAppNotification::STATUS_CANCELLED;
                    }
                }

                if ($terminal) {
                    $notification->forceFill([
                        'status' => $terminal,
                        'next_escalation_at' => null,
                        'snoozed_until' => null,
                        'actioned_at' => $terminal === InAppNotification::STATUS_ACTIONED ? now() : null,
                        'cancelled_at' => $terminal === InAppNotification::STATUS_CANCELLED ? now() : null,
                    ])->save();
                }
            });
    }
}
