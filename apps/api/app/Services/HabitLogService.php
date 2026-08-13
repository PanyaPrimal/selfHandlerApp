<?php

namespace App\Services;

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HabitLogService
{
    public function __construct(private readonly OccurrenceFactSynchronizer $occurrences) {}

    /** @param array<string, mixed> $data */
    public function upsert(Habit $habit, User $user, string $date, array $data): HabitLog
    {
        $this->assertOwner($habit, $user);

        return DB::transaction(function () use ($habit, $user, $date, $data): HabitLog {
            $occurrence = $this->occurrence($habit, $date, lock: true);
            $outcome = (string) ($data['outcome'] ?? '');

            if (! $habit->acceptsOutcome($outcome)) {
                throw ValidationException::withMessages([
                    'outcome' => __('messages.habit_outcome_incompatible'),
                ]);
            }

            $recorded = $outcome === HabitLog::OUTCOME_RECORDED;
            $value = $data['value'] ?? null;
            if (($recorded && (! is_numeric($value) || (float) $value < 0))
                || (! $recorded && $value !== null)) {
                throw ValidationException::withMessages([
                    'value' => __('messages.habit_value_incompatible'),
                ]);
            }

            $occurredAt = $outcome === HabitLog::OUTCOME_SKIPPED
                ? null
                : $this->parseOccurredAt($date, $data['occurred_time'] ?? null, $user->calendarTimezone());

            $log = HabitLog::query()->updateOrCreate([
                'user_id' => $user->id,
                'habit_id' => $habit->id,
                'log_date' => $date,
            ], [
                'outcome' => $outcome,
                'value' => $recorded ? round((float) $value, 3) : null,
                'occurred_at' => $occurredAt,
                'note' => $data['note'] ?? null,
            ]);

            $this->occurrences->syncFromHabitLog($log);
            $occurrence->refresh();

            return $log->fresh('habit');
        });
    }

    public function clear(Habit $habit, User $user, string $date): void
    {
        $this->assertOwner($habit, $user);

        DB::transaction(function () use ($habit, $user, $date): void {
            $this->occurrence($habit, $date, lock: true);
            $this->occurrences->clearForHabitDate($habit, $date);
            HabitLog::query()
                ->ownedBy($user)
                ->where('habit_id', $habit->id)
                ->where('log_date', $date)
                ->delete();
        });
    }

    private function occurrence(Habit $habit, string $date, bool $lock = false): PlannedOccurrence
    {
        $query = PlannedOccurrence::query()
            ->where(function ($query) use ($date): void {
                $query->where(function ($original) use ($date): void {
                    $original->where('occurrence_date', $date)->whereNull('rescheduled_to');
                })->orWhere('rescheduled_to', $date);
            })
            ->whereIn('recurring_rule_id', RecurringRule::query()
                ->where('owner_type', RecurringRule::OWNER_HABIT)
                ->where('owner_id', $habit->id)
                ->select('id'));

        if ($lock) {
            $query->lockForUpdate();
        }

        $matches = $query->get();
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages([
                'date' => __('messages.habit_date_not_scheduled'),
            ]);
        }

        return $matches->first();
    }

    private function parseOccurredAt(string $date, mixed $time, string $timezone): CarbonImmutable
    {
        if (! is_string($time) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw ValidationException::withMessages([
                'occurred_time' => __('messages.habit_time_required'),
            ]);
        }

        $wall = "{$date} {$time}";
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d H:i', $wall, $timezone);

        if (! $parsed || $parsed->format('Y-m-d H:i') !== $wall) {
            throw ValidationException::withMessages([
                'occurred_time' => __('messages.habit_time_nonexistent'),
            ]);
        }

        return $parsed->utc();
    }

    private function assertOwner(Habit $habit, User $user): void
    {
        abort_unless($habit->isOwnedBy($user), 404);
    }
}
