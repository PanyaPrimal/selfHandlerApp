<?php

namespace App\Services\Planner;

use App\Contracts\SchedulableSource;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\SupplementCourse;
use App\Models\User;
use App\Support\PlannerEntry;

class SupplementOccurrenceSource implements SchedulableSource
{
    public function name(): string
    {
        return 'supplement';
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
                ->where('owner_type', RecurringRule::OWNER_SUPPLEMENT_COURSE)->select('id'))
            ->with('recurringRule')->orderBy('occurrence_time')->orderBy('id')->get();
        if ($occurrences->isEmpty()) {
            return [];
        }
        $courses = SupplementCourse::query()->ownedBy($user)
            ->whereIn('id', $occurrences->pluck('recurringRule.owner_id')->filter())
            ->with('supplement')->get()->keyBy('id');

        return $occurrences->map(function (PlannedOccurrence $occurrence) use ($courses, $date): ?PlannerEntry {
            $course = $courses->get($occurrence->recurringRule?->owner_id);
            if (! $course || ($occurrence->status === PlannedOccurrence::STATUS_PLANNED
                && (! $course->is_active || $course->is_archived))) {
                return null;
            }

            return new PlannerEntry(
                source: $this->name(),
                sourceId: $occurrence->id,
                title: $course->name ?: $course->supplement->name,
                time: substr((string) $occurrence->occurrence_time, 0, 5),
                status: $occurrence->status,
                actions: $occurrence->hasFact() ? [] : ['skip', 'reschedule'],
                meta: [
                    'course_id' => $course->id,
                    'supplement_id' => $course->supplement_id,
                    'slot' => $occurrence->slot,
                    'occurrence_date' => $occurrence->occurrence_date->format('Y-m-d'),
                    'rescheduled_to' => $occurrence->rescheduled_to?->format('Y-m-d'),
                    'action_url' => '/supplements?date='.$date.'&course='.$course->id.'&slot='.$occurrence->slot,
                ],
            );
        })->filter()->values()->all();
    }
}
