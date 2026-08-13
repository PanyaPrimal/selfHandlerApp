<?php

namespace App\Services\Planner;

use App\Contracts\SchedulableSource;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\User;
use App\Models\WorkoutProgram;
use App\Support\PlannerEntry;

class WorkoutOccurrenceSource implements SchedulableSource
{
    public function name(): string
    {
        return 'workout';
    }

    public function entriesFor(User $user, string $date): array
    {
        $occurrences = PlannedOccurrence::query()->ownedBy($user)
            ->where(function ($query) use ($date): void {
                $query->where(function ($original) use ($date): void {
                    $original->where('occurrence_date', $date)->whereNull('rescheduled_to');
                })->orWhere('rescheduled_to', $date);
            })
            ->whereIn('recurring_rule_id', RecurringRule::query()->ownedBy($user)
                ->where('owner_type', RecurringRule::OWNER_WORKOUT_PROGRAM)->select('id'))
            ->with('recurringRule')->orderBy('occurrence_time')->orderBy('id')->get();
        $programs = WorkoutProgram::query()->ownedBy($user)
            ->whereIn('id', $occurrences->pluck('recurringRule.owner_id')->filter())
            ->get()->keyBy('id');

        return $occurrences->map(function (PlannedOccurrence $occurrence) use ($programs, $date): ?PlannerEntry {
            $program = $programs->get($occurrence->recurringRule?->owner_id);
            if (! $program || $program->is_archived) {
                return null;
            }

            return new PlannerEntry(
                source: $this->name(),
                sourceId: $occurrence->id,
                title: $program->name,
                time: $occurrence->occurrence_time ? substr((string) $occurrence->occurrence_time, 0, 5) : null,
                status: $occurrence->status,
                actions: $occurrence->hasFact() ? [] : ['skip', 'reschedule'],
                meta: [
                    'workout_program_id' => $program->id,
                    'workout_type' => $program->workout_type,
                    'intensity' => $program->intensity,
                    'occurrence_date' => $occurrence->occurrence_date->format('Y-m-d'),
                    'rescheduled_to' => $occurrence->rescheduled_to?->format('Y-m-d'),
                    'action_url' => '/workouts?date='.$date.'&program='.$program->id,
                ],
            );
        })->filter()->values()->all();
    }
}
