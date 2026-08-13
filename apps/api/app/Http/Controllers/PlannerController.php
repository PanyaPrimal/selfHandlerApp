<?php

namespace App\Http\Controllers;

use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Models\Routine;
use App\Services\Planner\DayAssembler;
use App\Services\Planner\SourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The day surface.
 *
 * Planner reads through the source contract and writes nothing owned by another
 * module: skipping a routine day writes the routine log the rest of the
 * application already reads, and only the reschedule pointer belongs here.
 */
class PlannerController extends Controller
{
    public function __construct(
        private readonly DayAssembler $assembler,
        private readonly SourceRegistry $sources,
        private readonly RoutineLogController $logs,
    ) {}

    public function day(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $date = $validated['date']
            ?? CarbonImmutable::now($user->calendarTimezone())->toDateString();

        return response()->json([
            ...$this->assembler->assemble($user, $date),
            'sources' => $this->sources->names(),
        ]);
    }

    /**
     * Move a planned routine day to another date.
     *
     * The expanded date is never overwritten: the occurrence keeps its identity
     * and its original day, and simply shows up on the new one.
     */
    public function reschedule(Request $request, PlannedOccurrence $occurrence): JsonResponse
    {
        $user = $request->user();
        abort_unless($occurrence->isOwnedBy($user), 404);

        $data = $request->validate([
            'rescheduled_to' => ['present', 'nullable', 'date_format:Y-m-d'],
        ]);

        $target = $data['rescheduled_to'];

        if ($target !== null) {
            $this->assertMovable($user, $occurrence, $target);
        }

        $occurrence->forceFill(['rescheduled_to' => $target])->save();

        return response()->json(['data' => $occurrence->fresh()]);
    }

    /**
     * Record that a planned routine day was skipped.
     *
     * This is a fact about the past, so it goes where such facts already live —
     * the routine log Today writes and progress and streaks already understand.
     * No parallel planner-side skip state exists.
     */
    public function skip(Request $request, PlannedOccurrence $occurrence): JsonResponse
    {
        $user = $request->user();
        abort_unless($occurrence->isOwnedBy($user), 404);

        $routine = $this->routineFor($occurrence);
        abort_unless($routine && $routine->isOwnedBy($user), 404);

        $date = ($occurrence->rescheduled_to ?? $occurrence->occurrence_date)->format('Y-m-d');

        $request->merge(['status' => 'skipped']);

        return $this->logs->upsert($request, $routine, $date);
    }

    private function assertMovable(mixed $user, PlannedOccurrence $occurrence, string $target): void
    {
        if ($occurrence->hasFact()) {
            throw ValidationException::withMessages([
                'rescheduled_to' => __('messages.move_has_result'),
            ]);
        }

        $today = CarbonImmutable::now($user->calendarTimezone())->toDateString();

        if ($target < $today) {
            throw ValidationException::withMessages([
                'rescheduled_to' => __('messages.move_not_past'),
            ]);
        }

        $until = $occurrence->recurringRule?->last_materialized_until?->format('Y-m-d');

        if ($until !== null && $target > $until) {
            throw ValidationException::withMessages([
                'rescheduled_to' => __('messages.planned_window', ['until' => $until]),
            ]);
        }

        if ($occurrence->recurringRule?->owner_type === RecurringRule::OWNER_HABIT
            && PlannedOccurrence::query()
                ->where('recurring_rule_id', $occurrence->recurring_rule_id)
                ->whereKeyNot($occurrence->id)
                ->where(function ($query) use ($target): void {
                    $query->where(function ($original) use ($target): void {
                        $original->where('occurrence_date', $target)->whereNull('rescheduled_to');
                    })->orWhere('rescheduled_to', $target);
                })
                ->exists()) {
            throw ValidationException::withMessages([
                'rescheduled_to' => __('messages.habit_move_collision'),
            ]);
        }
    }

    private function routineFor(PlannedOccurrence $occurrence): ?Routine
    {
        $rule = $occurrence->recurringRule;

        if (! $rule || $rule->owner_type !== RecurringRule::OWNER_ROUTINE) {
            return null;
        }

        return Routine::query()->whereKey($rule->owner_id)->first();
    }
}
