<?php

namespace App\Services\Notifications;

use App\Models\Habit;
use App\Models\InAppNotification;
use App\Models\Item;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\Routine;
use App\Models\SleepPlan;
use App\Models\SupplementCourse;
use App\Models\SupplementRestockProposal;
use App\Models\User;
use App\Models\WorkoutProgram;
use App\Services\RoutineDayProjectionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class NotificationSourceSynchronizer
{
    public function __construct(private readonly RoutineDayProjectionService $routineDays) {}

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

        $projection = $this->routineDays->project($user, $today);
        $selectedRoutineIds = collect([
            $projection['morning']['selected']['routine_id'] ?? null,
            $projection['evening']['selected']['routine_id'] ?? null,
            ...array_column($projection['anytime'], 'routine_id'),
        ])->filter();
        $routines = $this->activeRoutines($user)
            ->filter(fn (Routine $routine): bool => $selectedRoutineIds->contains($routine->id));
        $habits = $this->activeHabits($user);
        $sleepPlans = SleepPlan::query()->ownedBy($user)
            ->where('is_active', true)->where('is_archived', false)->get()->keyBy('id');
        $workoutPrograms = WorkoutProgram::query()->ownedBy($user)
            ->where('is_active', true)->where('is_archived', false)->get()->keyBy('id');
        $supplementCourses = SupplementCourse::query()->ownedBy($user)
            ->where('is_active', true)->where('is_archived', false)
            ->with('supplement')->get()->keyBy('id');
        $restockProposals = SupplementRestockProposal::query()->ownedBy($user)
            ->with('supplement')->get()->keyBy('id');
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
            $sleepPlans,
            $workoutPrograms,
            $supplementCourses,
            $restockProposals,
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

        if ($settings->categoryEnabled(InAppNotification::CATEGORY_SLEEP)) {
            foreach ($occurrences as $occurrence) {
                $plan = $this->sleepPlanForOccurrence($occurrence, $sleepPlans);
                $effectiveDate = $this->effectiveDate($occurrence);
                if (! $plan
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
                    'type' => InAppNotification::TYPE_SLEEP_REMINDER,
                    'category' => InAppNotification::CATEGORY_SLEEP,
                    'content' => ['title' => $plan->name, 'date' => $effectiveDate],
                    'action_url' => "/routines?sleep_date={$effectiveDate}",
                    'scheduled_at' => $scheduledAt,
                    'max_escalations' => (int) config('selfhandler.notifications.sleep.max_escalations', 2),
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

        if ($settings->categoryEnabled(InAppNotification::CATEGORY_WORKOUT)) {
            foreach ($occurrences as $occurrence) {
                $program = $this->workoutProgramForOccurrence($occurrence, $workoutPrograms);
                $effectiveDate = $this->effectiveDate($occurrence);
                if (! $program
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
                    'type' => InAppNotification::TYPE_WORKOUT_REMINDER,
                    'category' => InAppNotification::CATEGORY_WORKOUT,
                    'content' => ['title' => $program->name, 'date' => $effectiveDate],
                    'action_url' => "/workouts?date={$effectiveDate}&program={$program->id}",
                    'scheduled_at' => $scheduledAt,
                    'max_escalations' => (int) config('selfhandler.notifications.workout.max_escalations', 2),
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

        if ($settings->categoryEnabled(InAppNotification::CATEGORY_SUPPLEMENT)) {
            foreach ($occurrences as $occurrence) {
                $course = $this->supplementCourseForOccurrence($occurrence, $supplementCourses);
                $effectiveDate = $this->effectiveDate($occurrence);
                if (! $course
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
                    'type' => InAppNotification::TYPE_SUPPLEMENT_INTAKE,
                    'category' => InAppNotification::CATEGORY_SUPPLEMENT,
                    'content' => ['title' => $course->name ?: $course->supplement->name, 'date' => $effectiveDate],
                    'action_url' => "/supplements?date={$effectiveDate}&course={$course->id}&slot={$occurrence->slot}",
                    'scheduled_at' => $scheduledAt,
                    'max_escalations' => (int) config('selfhandler.notifications.supplement.max_escalations', 3),
                ]);
            }

            foreach ($restockProposals as $proposal) {
                if ($proposal->status !== SupplementRestockProposal::STATUS_OPEN) {
                    continue;
                }
                $written += $this->upsertInitial($user, [
                    'source_type' => InAppNotification::SOURCE_SUPPLEMENT_RESTOCK_PROPOSAL,
                    'source_id' => $proposal->id,
                    'type' => InAppNotification::TYPE_SUPPLEMENT_RESTOCK,
                    'category' => InAppNotification::CATEGORY_SUPPLEMENT,
                    'content' => [
                        'title' => $proposal->supplement->name,
                        'needed_by' => $proposal->needed_by->format('Y-m-d'),
                    ],
                    'action_url' => "/supplements?restock={$proposal->id}",
                    'scheduled_at' => $now,
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
        $deliveryDate = $notification->scheduled_at?->setTimezone($user->calendarTimezone())->toDateString();

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
                RecurringRule::OWNER_SLEEP_PLAN => SleepPlan::query()
                    ->ownedBy($user)
                    ->whereKey($occurrence->recurringRule->owner_id)
                    ->where('is_active', true)
                    ->where('is_archived', false)
                    ->exists(),
                RecurringRule::OWNER_WORKOUT_PROGRAM => WorkoutProgram::query()
                    ->ownedBy($user)
                    ->whereKey($occurrence->recurringRule->owner_id)
                    ->where('is_active', true)
                    ->where('is_archived', false)
                    ->exists(),
                RecurringRule::OWNER_SUPPLEMENT_COURSE => SupplementCourse::query()
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
                && ($this->effectiveDate($occurrence) === $today || $deliveryDate === $today)
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

        if ($notification->source_type === InAppNotification::SOURCE_SUPPLEMENT_RESTOCK_PROPOSAL) {
            $proposal = SupplementRestockProposal::query()->ownedBy($user)->find($notification->source_id);

            return $proposal?->status === SupplementRestockProposal::STATUS_OPEN
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

    public function sleepPlanForOccurrence(PlannedOccurrence $occurrence, Collection $plans): ?SleepPlan
    {
        $rule = $occurrence->recurringRule;

        return $rule?->owner_type === RecurringRule::OWNER_SLEEP_PLAN
            ? $plans->get($rule->owner_id)
            : null;
    }

    public function workoutProgramForOccurrence(PlannedOccurrence $occurrence, Collection $programs): ?WorkoutProgram
    {
        $rule = $occurrence->recurringRule;

        return $rule?->owner_type === RecurringRule::OWNER_WORKOUT_PROGRAM
            ? $programs->get($rule->owner_id)
            : null;
    }

    public function supplementCourseForOccurrence(PlannedOccurrence $occurrence, Collection $courses): ?SupplementCourse
    {
        $rule = $occurrence->recurringRule;

        return $rule?->owner_type === RecurringRule::OWNER_SUPPLEMENT_COURSE
            ? $courses->get($rule->owner_id)
            : null;
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
     * @param  Collection<int, SleepPlan>  $sleepPlans
     * @param  Collection<int, WorkoutProgram>  $workoutPrograms
     * @param  Collection<int, SupplementCourse>  $supplementCourses
     * @param  Collection<int, SupplementRestockProposal>  $restockProposals
     */
    private function closeInvalidExisting(
        User $user,
        Collection $occurrences,
        Collection $items,
        Collection $routines,
        Collection $habits,
        Collection $sleepPlans,
        Collection $workoutPrograms,
        Collection $supplementCourses,
        Collection $restockProposals,
        string $today,
    ): void {
        $settings = $user->ensureNotificationSettings();

        InAppNotification::query()
            ->ownedBy($user)
            ->whereIn('source_type', [
                InAppNotification::SOURCE_PLANNED_OCCURRENCE,
                InAppNotification::SOURCE_STORAGE_ITEM,
                InAppNotification::SOURCE_SUPPLEMENT_RESTOCK_PROPOSAL,
            ])
            ->whereIn('status', InAppNotification::ACTIVE_STATUSES)
            ->get()
            ->each(function (InAppNotification $notification) use (
                $settings,
                $occurrences,
                $items,
                $routines,
                $habits,
                $sleepPlans,
                $workoutPrograms,
                $supplementCourses,
                $restockProposals,
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
                            && ! $this->habitForOccurrence($occurrence, $habits)
                            && ! $this->sleepPlanForOccurrence($occurrence, $sleepPlans)
                            && ! $this->workoutProgramForOccurrence($occurrence, $workoutPrograms)
                            && ! $this->supplementCourseForOccurrence($occurrence, $supplementCourses))
                        || blank($occurrence->occurrence_time)
                        || $this->effectiveDate($occurrence) !== $today) {
                        $terminal = InAppNotification::STATUS_CANCELLED;
                    }
                } elseif ($notification->source_type === InAppNotification::SOURCE_SUPPLEMENT_RESTOCK_PROPOSAL) {
                    $proposal = $restockProposals->get($notification->source_id);
                    if (! $proposal || $proposal->status !== SupplementRestockProposal::STATUS_OPEN) {
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
