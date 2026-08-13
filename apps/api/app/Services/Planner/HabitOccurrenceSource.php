<?php

namespace App\Services\Planner;

use App\Contracts\SchedulableSource;
use App\Models\Habit;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\User;
use App\Support\PlannerEntry;
use Illuminate\Support\Collection;

/** Project active habit occurrences into Planner without copying their facts. */
class HabitOccurrenceSource implements SchedulableSource
{
    public function name(): string
    {
        return 'habit';
    }

    public function entriesFor(User $user, string $date): array
    {
        $occurrences = PlannedOccurrence::query()
            ->ownedBy($user)
            ->where(function ($query) use ($date): void {
                $query->where(function ($original) use ($date): void {
                    $original->where('occurrence_date', $date)->whereNull('rescheduled_to');
                })->orWhere('rescheduled_to', $date);
            })
            ->with('recurringRule')
            ->orderBy('occurrence_time')
            ->orderBy('id')
            ->get();

        $habits = $this->habitsFor($occurrences);

        return $occurrences
            ->map(function (PlannedOccurrence $occurrence) use ($habits): ?PlannerEntry {
                $habit = $habits->get($occurrence->recurringRule?->owner_id);

                if (! $habit) {
                    return null;
                }

                return new PlannerEntry(
                    source: $this->name(),
                    sourceId: $occurrence->id,
                    title: $habit->name,
                    time: $occurrence->occurrence_time
                        ? substr((string) $occurrence->occurrence_time, 0, 5)
                        : null,
                    status: $occurrence->status,
                    actions: $occurrence->hasFact() ? [] : ['reschedule'],
                    meta: [
                        'habit_id' => $habit->id,
                        'occurrence_date' => $occurrence->occurrence_date->format('Y-m-d'),
                        'rescheduled_to' => $occurrence->rescheduled_to?->format('Y-m-d'),
                        'kind' => $habit->kind,
                        'mode' => $habit->mode,
                    ],
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, PlannedOccurrence>  $occurrences
     * @return Collection<int, Habit>
     */
    private function habitsFor(Collection $occurrences): Collection
    {
        $ownerIds = $occurrences
            ->map(fn (PlannedOccurrence $occurrence): ?int => $occurrence->recurringRule?->owner_type === RecurringRule::OWNER_HABIT
                ? (int) $occurrence->recurringRule->owner_id
                : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ownerIds === []) {
            return collect();
        }

        return Habit::query()
            ->whereIn('id', $ownerIds)
            ->where('is_active', true)
            ->where('is_archived', false)
            ->get(['id', 'name', 'kind', 'mode'])
            ->keyBy('id');
    }
}
