<?php

namespace App\Services;

use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\SleepLog;
use App\Models\SleepPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SleepLogService
{
    public function __construct(private readonly OccurrenceFactSynchronizer $occurrences) {}

    /** @param array<string, mixed> $data */
    public function upsert(SleepPlan $plan, User $user, string $date, array $data): SleepLog
    {
        $this->assertOwner($plan, $user);

        return DB::transaction(function () use ($plan, $user, $date, $data): SleepLog {
            $this->occurrence($plan, $date, lock: true);
            $allowedBedDates = [$date, CarbonImmutable::parse($date)->addDay()->toDateString()];
            if (! in_array($data['actual_bed_date'] ?? null, $allowedBedDates, true)) {
                throw ValidationException::withMessages([
                    'actual_bed_date' => __('messages.sleep_bed_date'),
                ]);
            }

            $timezone = $user->calendarTimezone();
            $bed = $this->wallTime($data['actual_bed_date'], $data['actual_bed_time'], $timezone, 'actual_bed_time');
            $wake = $this->wallTime($data['actual_wake_date'], $data['actual_wake_time'], $timezone, 'actual_wake_time');
            $duration = $bed->diffInMinutes($wake, false);
            if ($duration <= 0 || $duration > 1440) {
                throw ValidationException::withMessages([
                    'actual_wake_time' => __('messages.sleep_duration_invalid'),
                ]);
            }

            $log = SleepLog::query()->updateOrCreate([
                'user_id' => $user->id,
                'sleep_plan_id' => $plan->id,
                'sleep_date' => $date,
            ], [
                'actual_bed_at' => $bed->utc(),
                'actual_wake_at' => $wake->utc(),
                'quality' => (int) $data['quality'],
                'note' => $data['note'] ?? null,
            ]);

            $this->occurrences->syncFromSleepLog($log);

            return $log->fresh('sleepPlan');
        });
    }

    public function clear(SleepPlan $plan, User $user, string $date): void
    {
        $this->assertOwner($plan, $user);

        DB::transaction(function () use ($plan, $user, $date): void {
            $this->occurrence($plan, $date, lock: true);
            $this->occurrences->clearForSleepDate($plan, $date);
            SleepLog::query()
                ->ownedBy($user)
                ->where('sleep_plan_id', $plan->id)
                ->where('sleep_date', $date)
                ->delete();
        });
    }

    private function occurrence(SleepPlan $plan, string $date, bool $lock = false): PlannedOccurrence
    {
        $query = $this->occurrenceQuery($plan, $date);
        if ($lock) {
            $query->lockForUpdate();
        }
        $matches = $query->get();
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages(['date' => __('messages.sleep_date_not_scheduled')]);
        }

        return $matches->first();
    }

    private function occurrenceQuery(SleepPlan $plan, string $date): Builder
    {
        return PlannedOccurrence::query()
            ->where(function ($query) use ($date): void {
                $query->where(function ($original) use ($date): void {
                    $original->where('occurrence_date', $date)->whereNull('rescheduled_to');
                })->orWhere('rescheduled_to', $date);
            })
            ->whereIn('recurring_rule_id', RecurringRule::query()
                ->where('owner_type', RecurringRule::OWNER_SLEEP_PLAN)
                ->where('owner_id', $plan->id)
                ->select('id'));
    }

    private function wallTime(string $date, string $time, string $timezone, string $field): CarbonImmutable
    {
        $wall = "{$date} {$time}";
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d H:i', $wall, $timezone);
        if (! $parsed || $parsed->format('Y-m-d H:i') !== $wall) {
            throw ValidationException::withMessages([$field => __('messages.sleep_time_nonexistent')]);
        }

        return $parsed;
    }

    private function assertOwner(SleepPlan $plan, User $user): void
    {
        abort_unless($plan->isOwnedBy($user), 404);
    }
}
