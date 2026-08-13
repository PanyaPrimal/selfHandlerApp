<?php

namespace App\Services;

use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\Routine;
use App\Models\RoutineActivityLog;
use App\Models\RoutineDaySelection;
use App\Models\RoutineLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoutineDayProjectionService
{
    public function __construct(private readonly RoutineActivitySummaryService $summaries) {}

    /** @return array<string, mixed> */
    public function project(User $user, string $date): array
    {
        $date = $this->date($date, $user->calendarTimezone());
        $occurrenceRows = PlannedOccurrence::query()
            ->ownedBy($user)
            ->join('recurring_rules', 'recurring_rules.id', '=', 'planned_occurrences.recurring_rule_id')
            ->where('recurring_rules.owner_type', RecurringRule::OWNER_ROUTINE)
            ->where(function ($query) use ($date): void {
                $query->where(function ($original) use ($date): void {
                    $original->where('planned_occurrences.occurrence_date', $date)
                        ->whereNull('planned_occurrences.rescheduled_to');
                })->orWhere('planned_occurrences.rescheduled_to', $date);
            })
            ->get(['planned_occurrences.*', 'recurring_rules.owner_id']);
        $occurrences = collect();
        foreach ($occurrenceRows->groupBy(fn (PlannedOccurrence $row): int => (int) $row->getAttribute('owner_id')) as $ownerId => $rows) {
            $occurrences->put((int) $ownerId, $rows->sortBy('id')->first());
        }

        $routines = Routine::query()
            ->ownedBy($user)
            ->where('is_active', true)
            ->where('is_archived', false)
            ->whereIn('id', $occurrences->keys())
            ->with([
                'recurringRule.ruleWeekdays',
                'activities' => fn ($query) => $query
                    ->withCount('logs')
                    ->with(['logs' => fn ($logs) => $logs->whereDate('log_date', $date)]),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
        $routineById = $routines->keyBy('id');
        $selections = RoutineDaySelection::query()
            ->ownedBy($user)
            ->whereDate('selection_date', $date)
            ->get()
            ->keyBy('period');

        $periods = [];
        $selectedRoutineIds = [];
        foreach ([Routine::DAY_PERIOD_MORNING, Routine::DAY_PERIOD_EVENING] as $period) {
            $candidates = $routines
                ->where('day_period', $period)
                ->map(fn (Routine $routine): array => $this->candidate($routine, $occurrences->get($routine->id)))
                ->values();
            $selection = $selections->get($period);
            $source = $selection ? 'explicit' : 'default';
            $selected = $selection
                ? $candidates->firstWhere('routine_id', $selection->routine_id)
                : $candidates->first();
            if ($selected !== null) {
                $selectedRoutineIds[] = $selected['routine_id'];
            }

            $periods[$period] = [
                'period' => $period,
                'source' => $source,
                'selected' => $selected,
                'candidates' => $candidates->all(),
            ];
        }

        $anytime = $routines
            ->where('day_period', Routine::DAY_PERIOD_ANYTIME)
            ->map(fn (Routine $routine): array => $this->candidate($routine, $occurrences->get($routine->id)))
            ->values();
        $selectedRoutineIds = array_merge($selectedRoutineIds, $anytime->pluck('routine_id')->all());

        return [
            'date' => $date,
            'morning' => $periods[Routine::DAY_PERIOD_MORNING],
            'evening' => $periods[Routine::DAY_PERIOD_EVENING],
            'anytime' => $anytime->all(),
            'activity_summary' => $this->summaries->summarize(
                collect($selectedRoutineIds)->map(fn ($id) => $routineById->get($id))->filter()->values(),
            ),
        ];
    }

    /** @param array{morning_routine_id:?int, evening_routine_id:?int} $choices
     * @return array<string, mixed>
     */
    public function replace(User $user, string $date, array $choices): array
    {
        $date = $this->date($date, $user->calendarTimezone());

        return DB::transaction(function () use ($user, $date, $choices): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $current = $this->project($user, $date);

            foreach ([Routine::DAY_PERIOD_MORNING, Routine::DAY_PERIOD_EVENING] as $period) {
                $field = "{$period}_routine_id";
                $requestedId = $choices[$field];
                if ($requestedId !== null) {
                    $routine = Routine::query()->whereKey($requestedId)->first();
                    abort_unless($routine?->isOwnedBy($user), 404);
                    if (! collect($current[$period]['candidates'])->contains('routine_id', $requestedId)) {
                        throw ValidationException::withMessages([
                            $field => __('messages.routine_selection_invalid'),
                        ]);
                    }
                }

                $previousId = $current[$period]['selected']['routine_id'] ?? null;
                if ($previousId !== null && $previousId !== $requestedId && $this->hasFact($user, $previousId, $date)) {
                    throw ValidationException::withMessages([
                        $field => __('messages.routine_selection_fact'),
                    ]);
                }
            }

            foreach ([Routine::DAY_PERIOD_MORNING, Routine::DAY_PERIOD_EVENING] as $period) {
                RoutineDaySelection::query()->updateOrCreate([
                    'user_id' => $user->id,
                    'selection_date' => $date,
                    'period' => $period,
                ], [
                    'routine_id' => $choices["{$period}_routine_id"],
                ]);
            }

            return $this->project($user, $date);
        });
    }

    public function assertSelected(Routine $routine, User $user, string $date): void
    {
        abort_unless($routine->isOwnedBy($user), 404);
        $projection = $this->project($user, $date);
        $selected = match ($routine->day_period) {
            Routine::DAY_PERIOD_MORNING => $projection['morning']['selected']['routine_id'] ?? null,
            Routine::DAY_PERIOD_EVENING => $projection['evening']['selected']['routine_id'] ?? null,
            Routine::DAY_PERIOD_ANYTIME => collect($projection['anytime'])->firstWhere('routine_id', $routine->id)['routine_id'] ?? null,
            default => null,
        };
        if ((int) $selected !== (int) $routine->id) {
            throw ValidationException::withMessages([
                'date' => __('messages.routine_activity_not_selected'),
            ]);
        }
    }

    private function candidate(Routine $routine, PlannedOccurrence $occurrence): array
    {
        return [
            'routine_id' => $routine->id,
            'occurrence_id' => $occurrence->id,
            'name' => $routine->name,
            'day_period' => $routine->day_period,
            'preferred_time' => $routine->preferred_time,
            'sort_order' => $routine->sort_order,
        ];
    }

    private function hasFact(User $user, int $routineId, string $date): bool
    {
        return RoutineLog::query()->ownedBy($user)
            ->where('routine_id', $routineId)->whereDate('log_date', $date)->exists()
            || RoutineActivityLog::query()->ownedBy($user)
                ->whereDate('log_date', $date)
                ->whereIn('routine_activity_id', Routine::query()->whereKey($routineId)
                    ->firstOrFail()->activities()->select('id'))
                ->exists();
    }

    private function date(string $date, string $timezone): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw ValidationException::withMessages(['date' => __('validation.date_format', ['format' => 'Y-m-d'])]);
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        } catch (\Throwable) {
            $parsed = false;
        }
        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages(['date' => __('validation.date_format', ['format' => 'Y-m-d'])]);
        }

        return $date;
    }
}
