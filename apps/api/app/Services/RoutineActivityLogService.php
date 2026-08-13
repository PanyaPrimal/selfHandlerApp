<?php

namespace App\Services;

use App\Models\Routine;
use App\Models\RoutineActivity;
use App\Models\RoutineActivityLog;
use App\Models\RoutineLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoutineActivityLogService
{
    public function __construct(
        private readonly RoutineDayProjectionService $projection,
        private readonly OccurrenceFactSynchronizer $occurrences,
    ) {}

    /** @param array<string, mixed> $payload */
    public function upsert(
        Routine $routine,
        RoutineActivity $activity,
        User $user,
        string $date,
        array $payload,
    ): RoutineActivityLog {
        $this->assertOwnedChild($routine, $activity, $user);
        $this->projection->assertSelected($routine, $user, $date);
        $this->assertProgress($activity, $payload);

        return DB::transaction(function () use ($routine, $activity, $user, $date, $payload): RoutineActivityLog {
            $log = RoutineActivityLog::query()
                ->ownedBy($user)
                ->where('routine_activity_id', $activity->id)
                ->whereDate('log_date', $date)
                ->lockForUpdate()
                ->first();
            $wasDone = $log?->status === RoutineActivityLog::STATUS_DONE;
            $completedAt = $wasDone ? $log->completed_at : now();
            $values = [
                'status' => $payload['status'],
                'progress_value' => $payload['progress_value'] ?? null,
                'note' => $payload['note'] ?? null,
                'completed_at' => $payload['status'] === RoutineActivityLog::STATUS_DONE ? $completedAt : null,
            ];

            if ($log) {
                $log->update($values);
            } else {
                $log = RoutineActivityLog::create([
                    ...$values,
                    'user_id' => $user->id,
                    'routine_activity_id' => $activity->id,
                    'log_date' => $date,
                ]);
            }

            $this->syncParent($routine, $user, $date);

            return $log->fresh();
        });
    }

    public function clear(Routine $routine, RoutineActivity $activity, User $user, string $date): void
    {
        $this->assertOwnedChild($routine, $activity, $user);
        $this->projection->assertSelected($routine, $user, $date);

        DB::transaction(function () use ($routine, $activity, $user, $date): void {
            RoutineActivityLog::query()->ownedBy($user)
                ->where('routine_activity_id', $activity->id)
                ->whereDate('log_date', $date)
                ->delete();
            $this->syncParent($routine, $user, $date);
        });
    }

    public function skipRemaining(Routine $routine, User $user, string $date): void
    {
        abort_unless($routine->isOwnedBy($user), 404);
        $this->projection->assertSelected($routine, $user, $date);

        DB::transaction(function () use ($routine, $user, $date): void {
            $activities = $routine->activities()->lockForUpdate()->get();
            foreach ($activities as $activity) {
                RoutineActivityLog::query()->firstOrCreate([
                    'user_id' => $user->id,
                    'routine_activity_id' => $activity->id,
                    'log_date' => $date,
                ], [
                    'status' => RoutineActivityLog::STATUS_SKIPPED,
                    'progress_value' => null,
                    'note' => null,
                    'completed_at' => null,
                ]);
            }
            $this->syncParent($routine, $user, $date);
        });
    }

    public function clearWholeDate(Routine $routine, User $user, string $date): void
    {
        abort_unless($routine->isOwnedBy($user), 404);

        DB::transaction(function () use ($routine, $user, $date): void {
            RoutineActivityLog::query()->ownedBy($user)
                ->whereDate('log_date', $date)
                ->whereIn('routine_activity_id', $routine->activities()->select('id'))
                ->delete();
            RoutineLog::query()->ownedBy($user)
                ->where('routine_id', $routine->id)
                ->whereDate('log_date', $date)
                ->delete();
            $this->occurrences->clearForRoutineDate($routine, $date);
        });
    }

    private function syncParent(Routine $routine, User $user, string $date): void
    {
        $activityIds = $routine->activities()->pluck('id');
        $logs = RoutineActivityLog::query()->ownedBy($user)
            ->whereDate('log_date', $date)
            ->whereIn('routine_activity_id', $activityIds)
            ->get();

        if ($activityIds->isEmpty() || $logs->count() !== $activityIds->count()) {
            RoutineLog::query()->ownedBy($user)
                ->where('routine_id', $routine->id)
                ->whereDate('log_date', $date)
                ->delete();
            $this->occurrences->clearForRoutineDate($routine, $date);

            return;
        }

        $status = $logs->every(fn (RoutineActivityLog $log): bool => $log->status === RoutineActivityLog::STATUS_DONE)
            ? RoutineLog::STATUS_DONE
            : RoutineLog::STATUS_SKIPPED;
        $parent = RoutineLog::query()->ownedBy($user)
            ->where('routine_id', $routine->id)
            ->whereDate('log_date', $date)
            ->lockForUpdate()
            ->first();
        $wasDone = $parent?->status === RoutineLog::STATUS_DONE;
        $values = [
            'status' => $status,
            'note' => null,
            'completed_at' => $status === RoutineLog::STATUS_DONE
                ? ($wasDone ? $parent->completed_at : now())
                : null,
        ];

        if ($parent) {
            $parent->update($values);
        } else {
            $parent = RoutineLog::create([
                ...$values,
                'user_id' => $user->id,
                'routine_id' => $routine->id,
                'log_date' => $date,
            ]);
        }
        $this->occurrences->syncFromLog($parent);
    }

    /** @param array<string, mixed> $payload */
    private function assertProgress(RoutineActivity $activity, array $payload): void
    {
        $progress = $payload['progress_value'] ?? null;
        $total = $activity->progress_total === null ? null : (float) $activity->progress_total;
        $valid = $payload['status'] === RoutineActivityLog::STATUS_DONE
            ? ($total === null
                ? $progress === null
                : $progress !== null && (float) $progress >= 0 && (float) $progress <= $total)
            : $progress === null;

        if (! $valid) {
            throw ValidationException::withMessages([
                'progress_value' => __('messages.routine_activity_progress'),
            ]);
        }
    }

    private function assertOwnedChild(Routine $routine, RoutineActivity $activity, User $user): void
    {
        abort_unless(
            $routine->isOwnedBy($user)
            && $activity->isOwnedBy($user)
            && (int) $activity->routine_id === (int) $routine->id,
            404,
        );
    }
}
