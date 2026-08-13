<?php

namespace App\Services;

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\Routine;
use App\Models\RoutineLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a materialized occurrence in step with the fact that satisfied it.
 *
 * `routine_logs` is the authoritative record of what happened; the occurrence
 * only mirrors it, so the engine can later answer "was this planned day done"
 * without re-deriving it. Because the mirror is derived, it is always
 * recomputable — `reconcile()` rebuilds it from the logs alone.
 */
class OccurrenceFactSynchronizer
{
    public function syncFromLog(RoutineLog $log): void
    {
        $this->occurrenceQuery($log->routine_id, (string) $log->log_date->format('Y-m-d'))
            ->update([
                'status' => $log->status,
                'routine_log_id' => $log->id,
                'updated_at' => now(),
            ]);
    }

    public function clearForRoutineDate(Routine $routine, string $date): void
    {
        $this->occurrenceQuery($routine->id, $date)->update([
            'status' => PlannedOccurrence::STATUS_PLANNED,
            'routine_log_id' => null,
            'updated_at' => now(),
        ]);
    }

    public function syncFromHabitLog(HabitLog $log): void
    {
        $this->habitOccurrenceQuery($log->habit_id, $log->log_date->format('Y-m-d'))
            ->update([
                'status' => $log->outcome === HabitLog::OUTCOME_SKIPPED
                    ? PlannedOccurrence::STATUS_SKIPPED
                    : PlannedOccurrence::STATUS_DONE,
                'habit_log_id' => $log->id,
                'updated_at' => now(),
            ]);
    }

    public function clearForHabitDate(Habit $habit, string $date): void
    {
        $this->habitOccurrenceQuery($habit->id, $date)->update([
            'status' => PlannedOccurrence::STATUS_PLANNED,
            'habit_log_id' => null,
            'updated_at' => now(),
        ]);
    }

    /**
     * Rebuild every derived occurrence status for a user from the logs.
     *
     * @return int occurrences touched
     */
    public function reconcile(User $user): int
    {
        return DB::transaction(function () use ($user): int {
            $touched = PlannedOccurrence::query()
                ->ownedBy($user)
                ->update([
                    'status' => PlannedOccurrence::STATUS_PLANNED,
                    'routine_log_id' => null,
                    'habit_log_id' => null,
                    'updated_at' => now(),
                ]);

            RoutineLog::query()
                ->ownedBy($user)
                ->orderBy('id')
                ->chunk(500, function ($logs): void {
                    foreach ($logs as $log) {
                        $this->syncFromLog($log);
                    }
                });

            HabitLog::query()
                ->ownedBy($user)
                ->orderBy('id')
                ->chunk(500, function ($logs): void {
                    foreach ($logs as $log) {
                        $this->syncFromHabitLog($log);
                    }
                });

            return $touched;
        });
    }

    /**
     * The occurrence rows belonging to one routine's calendar day.
     */
    private function occurrenceQuery(int $routineId, string $date): Builder
    {
        return PlannedOccurrence::query()
            ->where(function ($query) use ($date): void {
                $query->where(function ($original) use ($date): void {
                    $original->where('occurrence_date', $date)->whereNull('rescheduled_to');
                })->orWhere('rescheduled_to', $date);
            })
            ->whereIn('recurring_rule_id', RecurringRule::query()
                ->where('owner_type', RecurringRule::OWNER_ROUTINE)
                ->where('owner_id', $routineId)
                ->select('id'));
    }

    private function habitOccurrenceQuery(int $habitId, string $date): Builder
    {
        return PlannedOccurrence::query()
            ->where(function ($query) use ($date): void {
                $query->where(function ($original) use ($date): void {
                    $original->where('occurrence_date', $date)->whereNull('rescheduled_to');
                })->orWhere('rescheduled_to', $date);
            })
            ->whereIn('recurring_rule_id', RecurringRule::query()
                ->where('owner_type', RecurringRule::OWNER_HABIT)
                ->where('owner_id', $habitId)
                ->select('id'));
    }
}
