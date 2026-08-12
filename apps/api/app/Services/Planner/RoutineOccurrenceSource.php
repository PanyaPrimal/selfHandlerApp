<?php

namespace App\Services\Planner;

use App\Contracts\SchedulableSource;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\Routine;
use App\Models\User;
use App\Support\PlannerEntry;
use Illuminate\Support\Collection;

/**
 * Routine days, read from the materialized window of the recurrence engine.
 *
 * A day is shown where it is actually planned: an occurrence that carries a
 * reschedule appears on that day and not on the one the rule expanded, which is
 * why both directions are queried.
 */
class RoutineOccurrenceSource implements SchedulableSource
{
    public function name(): string
    {
        return 'routine';
    }

    public function entriesFor(User $user, string $date): array
    {
        $occurrences = PlannedOccurrence::query()
            ->ownedBy($user)
            ->where(function ($query) use ($date): void {
                // Planned here and not moved away, or moved here from elsewhere.
                $query->where(function ($original) use ($date): void {
                    $original->where('occurrence_date', $date)->whereNull('rescheduled_to');
                })->orWhere('rescheduled_to', $date);
            })
            ->with('recurringRule')
            ->orderBy('occurrence_time')
            ->orderBy('id')
            ->get();

        if ($occurrences->isEmpty()) {
            return [];
        }

        $routines = $this->routinesFor($occurrences);

        return $occurrences
            ->map(function (PlannedOccurrence $occurrence) use ($routines): ?PlannerEntry {
                $routine = $routines->get($occurrence->recurringRule?->owner_id);

                // A routine that was deleted leaves its window behind briefly;
                // showing a nameless row would be worse than showing nothing.
                if (! $routine) {
                    return null;
                }

                return new PlannerEntry(
                    source: $this->name(),
                    sourceId: $occurrence->id,
                    title: $routine->name,
                    time: $occurrence->occurrence_time
                        ? substr((string) $occurrence->occurrence_time, 0, 5)
                        : null,
                    status: $occurrence->status,
                    // Only an untouched day can be moved or skipped.
                    actions: $occurrence->routine_log_id === null ? ['skip', 'reschedule'] : [],
                    meta: [
                        'routine_id' => $routine->id,
                        'occurrence_date' => $occurrence->occurrence_date->format('Y-m-d'),
                        'rescheduled_to' => $occurrence->rescheduled_to?->format('Y-m-d'),
                        'kind' => $routine->kind,
                    ],
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The routines behind these occurrences, in one query.
     *
     * @param  Collection<int, PlannedOccurrence>  $occurrences
     * @return Collection<int, Routine>
     */
    private function routinesFor(Collection $occurrences): Collection
    {
        $ownerIds = $occurrences
            ->map(fn (PlannedOccurrence $occurrence): ?int => $occurrence->recurringRule?->owner_type === RecurringRule::OWNER_ROUTINE
                ? (int) $occurrence->recurringRule->owner_id
                : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ownerIds === []) {
            return collect();
        }

        return Routine::query()
            ->whereIn('id', $ownerIds)
            ->where('is_archived', false)
            ->get(['id', 'name', 'kind'])
            ->keyBy('id');
    }
}
