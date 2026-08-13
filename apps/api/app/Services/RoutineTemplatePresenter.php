<?php

namespace App\Services;

use App\Models\Routine;
use App\Models\User;

class RoutineTemplatePresenter
{
    /** @return array<string, mixed> */
    public function payload(Routine $routine, User $user, string $date): array
    {
        abort_unless($routine->isOwnedBy($user), 404);
        $routine->load([
            'activities' => fn ($query) => $query
                ->withCount('logs')
                ->with(['logs' => fn ($logs) => $logs->whereDate('log_date', $date)]),
            'logs' => fn ($logs) => $logs->whereDate('log_date', $date),
        ]);
        $parent = $routine->logs->first();

        return [
            'id' => $routine->id,
            'name' => $routine->name,
            'day_period' => $routine->day_period,
            'activities' => $routine->activities->map(function ($activity): array {
                $log = $activity->logs->first();

                return [
                    'id' => $activity->id,
                    'name' => $activity->name,
                    'sort_order' => $activity->sort_order,
                    'preferred_time' => $activity->preferred_time ? substr((string) $activity->preferred_time, 0, 5) : null,
                    'progress_total' => $activity->progress_total === null ? null : (float) $activity->progress_total,
                    'has_facts' => $activity->logs_count > 0,
                    'selected_day_log' => $log ? [
                        'id' => $log->id,
                        'routine_activity_id' => $log->routine_activity_id,
                        'log_date' => $log->log_date->format('Y-m-d'),
                        'status' => $log->status,
                        'progress_value' => $log->progress_value === null ? null : (float) $log->progress_value,
                        'note' => $log->note,
                        'completed_at' => $log->completed_at?->toISOString(),
                    ] : null,
                ];
            })->values()->all(),
            'parent_state' => $parent?->status ?? 'pending',
        ];
    }
}
