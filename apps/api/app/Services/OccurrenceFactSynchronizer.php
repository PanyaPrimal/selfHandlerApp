<?php

namespace App\Services;

use App\Models\FinanceOccurrenceFact;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\Routine;
use App\Models\RoutineLog;
use App\Models\SleepLog;
use App\Models\SleepPlan;
use App\Models\SupplementIntake;
use App\Models\User;
use App\Models\WorkoutProgram;
use App\Models\WorkoutSession;
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

    public function syncFromSleepLog(SleepLog $log): void
    {
        $this->sleepOccurrenceQuery($log->sleep_plan_id, $log->sleep_date->format('Y-m-d'))
            ->update([
                'status' => PlannedOccurrence::STATUS_DONE,
                'sleep_log_id' => $log->id,
                'updated_at' => now(),
            ]);
    }

    public function clearForSleepDate(SleepPlan $plan, string $date): void
    {
        $this->sleepOccurrenceQuery($plan->id, $date)->update([
            'status' => PlannedOccurrence::STATUS_PLANNED,
            'sleep_log_id' => null,
            'updated_at' => now(),
        ]);
    }

    public function syncFromWorkoutSession(WorkoutSession $session): void
    {
        if ($session->workout_program_id === null) {
            return;
        }

        $this->workoutOccurrenceQuery($session->workout_program_id, $session->performed_on->format('Y-m-d'))
            ->update([
                'status' => $session->outcome === WorkoutSession::OUTCOME_SKIPPED
                    ? PlannedOccurrence::STATUS_SKIPPED
                    : PlannedOccurrence::STATUS_DONE,
                'workout_session_id' => $session->id,
                'updated_at' => now(),
            ]);
    }

    public function clearForWorkoutDate(WorkoutProgram $program, string $date): void
    {
        $this->workoutOccurrenceQuery($program->id, $date)->update([
            'status' => PlannedOccurrence::STATUS_PLANNED,
            'workout_session_id' => null,
            'updated_at' => now(),
        ]);
    }

    public function syncFromSupplementIntake(SupplementIntake $intake): void
    {
        PlannedOccurrence::query()
            ->where('user_id', $intake->user_id)
            ->where('occurrence_date', $intake->planned_on->format('Y-m-d'))
            ->where('slot', $intake->slot)
            ->whereIn('recurring_rule_id', RecurringRule::query()
                ->where('owner_type', RecurringRule::OWNER_SUPPLEMENT_COURSE)
                ->where('owner_id', $intake->supplement_course_id)
                ->select('id'))
            ->update([
                'status' => $intake->outcome === SupplementIntake::OUTCOME_SKIPPED
                    ? PlannedOccurrence::STATUS_SKIPPED
                    : PlannedOccurrence::STATUS_DONE,
                'supplement_intake_id' => $intake->id,
                'updated_at' => now(),
            ]);
    }

    public function clearForSupplementOccurrence(PlannedOccurrence $occurrence): void
    {
        $occurrence->forceFill([
            'status' => PlannedOccurrence::STATUS_PLANNED,
            'supplement_intake_id' => null,
        ])->save();
    }

    public function syncFromFinanceFact(FinanceOccurrenceFact $fact): void
    {
        PlannedOccurrence::query()
            ->whereKey($fact->planned_occurrence_id)
            ->where('user_id', $fact->user_id)
            ->update([
                'status' => $fact->outcome === FinanceOccurrenceFact::OUTCOME_SKIPPED
                    ? PlannedOccurrence::STATUS_SKIPPED : PlannedOccurrence::STATUS_DONE,
                'finance_occurrence_fact_id' => $fact->id,
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
                    'sleep_log_id' => null,
                    'workout_session_id' => null,
                    'supplement_intake_id' => null,
                    'finance_occurrence_fact_id' => null,
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

            SleepLog::query()
                ->ownedBy($user)
                ->orderBy('id')
                ->chunk(500, function ($logs): void {
                    foreach ($logs as $log) {
                        $this->syncFromSleepLog($log);
                    }
                });

            WorkoutSession::query()
                ->ownedBy($user)
                ->whereNotNull('workout_program_id')
                ->orderBy('id')
                ->chunk(500, function ($sessions): void {
                    foreach ($sessions as $session) {
                        $this->syncFromWorkoutSession($session);
                    }
                });

            SupplementIntake::query()
                ->ownedBy($user)
                ->orderBy('id')
                ->chunk(500, function ($intakes): void {
                    foreach ($intakes as $intake) {
                        $this->syncFromSupplementIntake($intake);
                    }
                });

            FinanceOccurrenceFact::query()
                ->ownedBy($user)
                ->orderBy('id')
                ->chunk(500, function ($facts): void {
                    foreach ($facts as $fact) {
                        $this->syncFromFinanceFact($fact);
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

    private function sleepOccurrenceQuery(int $sleepPlanId, string $date): Builder
    {
        return PlannedOccurrence::query()
            ->where(function ($query) use ($date): void {
                $query->where(function ($original) use ($date): void {
                    $original->where('occurrence_date', $date)->whereNull('rescheduled_to');
                })->orWhere('rescheduled_to', $date);
            })
            ->whereIn('recurring_rule_id', RecurringRule::query()
                ->where('owner_type', RecurringRule::OWNER_SLEEP_PLAN)
                ->where('owner_id', $sleepPlanId)
                ->select('id'));
    }

    private function workoutOccurrenceQuery(int $programId, string $date): Builder
    {
        return PlannedOccurrence::query()
            ->where(function ($query) use ($date): void {
                $query->where(function ($original) use ($date): void {
                    $original->where('occurrence_date', $date)->whereNull('rescheduled_to');
                })->orWhere('rescheduled_to', $date);
            })
            ->whereIn('recurring_rule_id', RecurringRule::query()
                ->where('owner_type', RecurringRule::OWNER_WORKOUT_PROGRAM)
                ->where('owner_id', $programId)
                ->select('id'));
    }
}
