<?php

namespace App\Http\Controllers;

use App\Models\DailyReview;
use App\Models\Routine;
use App\Models\RoutineLog;
use App\Services\NutritionSummaryService;
use App\Services\RoutineDayProjectionService;
use App\Services\RoutineProgressService;
use App\Services\RoutineScheduleService;
use App\Services\SleepStatisticsService;
use App\Services\WorkoutStatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TodayController extends Controller
{
    public function __construct(
        private readonly RoutineDayProjectionService $routineDays,
        private readonly RoutineProgressService $progressService,
        private readonly RoutineScheduleService $scheduleService,
        private readonly SleepStatisticsService $sleepStatistics,
        private readonly WorkoutStatisticsService $workoutStatistics,
        private readonly NutritionSummaryService $nutritionSummary,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $timezone = $user->calendarTimezone();
        $date = isset($validated['date'])
            ? CarbonImmutable::parse($validated['date'], $timezone)->startOfDay()
            : CarbonImmutable::now($timezone)->startOfDay();
        $isHistoricalDate = $date->isBefore(CarbonImmutable::now($timezone)->startOfDay());
        $dateValue = $date->toDateString();
        $projection = $this->routineDays->project($user, $dateValue);
        $selectedRoutineIds = collect([
            $projection['morning']['selected']['routine_id'] ?? null,
            $projection['evening']['selected']['routine_id'] ?? null,
            ...array_column($projection['anytime'], 'routine_id'),
        ])->filter()->unique();

        $logs = RoutineLog::query()
            ->ownedBy($user)
            ->where('log_date', $dateValue)
            ->get()
            ->keyBy('routine_id');

        $routines = Routine::query()
            ->ownedBy($user)
            ->with([
                'goals' => fn ($query) => $query
                    ->ownedBy($user)
                    ->where('goals.status', 'active')
                    ->where('goals.is_archived', false)
                    ->orderBy('goals.name')
                    ->orderBy('goals.id'),
                'recurringRule.ruleWeekdays',
                'activities' => fn ($query) => $query
                    ->withCount('logs')
                    ->with(['logs' => fn ($activityLogs) => $activityLogs->whereDate('log_date', $dateValue)]),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->filter(function (Routine $routine) use ($selectedRoutineIds, $isHistoricalDate, $logs, $date, $timezone): bool {
                if ($selectedRoutineIds->contains($routine->id)) {
                    return true;
                }

                // Feature 001 supports arbitrary calendar reads, including days
                // outside the durable recurrence window. Existing routines were
                // migrated to anytime, so retain that read contract without
                // making the new morning/evening selection rules diverge.
                if ($routine->day_period === Routine::DAY_PERIOD_ANYTIME
                    && $this->scheduleService->isScheduledFor($routine, $date, $timezone)) {
                    return true;
                }

                return $isHistoricalDate && $logs->has($routine->id);
            })
            ->values();

        $routineIds = $routines->pluck('id');
        $logs = $logs->filter(
            fn (RoutineLog $log): bool => $routineIds->contains($log->routine_id),
        );

        $done = $logs->where('status', 'done')->count();
        $skipped = $logs->where('status', 'skipped')->count();
        $scheduled = $routines->count();

        $review = DailyReview::query()
            ->ownedBy($user)
            ->whereDate('review_date', $date)
            ->first();
        $progress = $this->progressService->calculate($user, $date);
        $workouts = $this->workoutStatistics->forRange($user, $dateValue, $dateValue);

        return response()->json([
            'date' => $date->toDateString(),
            'summary' => [
                'scheduled' => $scheduled,
                'done' => $done,
                'skipped' => $skipped,
                'pending' => max(0, $scheduled - $done - $skipped),
                'completion_rate' => $scheduled === 0 ? 0 : round(($done / $scheduled) * 100, 2),
            ],
            'routines' => $routines->map(fn (Routine $routine): array => [
                'id' => $routine->id,
                'name' => $routine->name,
                'description' => $routine->description,
                'kind' => $routine->kind,
                'day_period' => $routine->day_period,
                'preferred_time' => $routine->preferred_time,
                'sort_order' => $routine->sort_order,
                'is_active' => $routine->is_active,
                'is_archived' => $routine->is_archived,
                'log' => $logs->get($routine->id),
                'parent_state' => $logs->get($routine->id)?->status ?? 'pending',
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
                })->values(),
                'current_streak' => $progress['routine_streaks'][$routine->id] ?? 0,
                'goals' => $routine->goals->map(fn ($goal): array => [
                    'id' => $goal->id,
                    'name' => $goal->name,
                    'status' => $goal->status,
                ])->values(),
            ]),
            'goals' => $routines
                ->flatMap(fn (Routine $routine) => $routine->goals)
                ->unique('id')
                ->values()
                ->map(fn ($goal): array => [
                    'id' => $goal->id,
                    'name' => $goal->name,
                    'status' => $goal->status,
                    'target_date' => $goal->target_date?->toDateString(),
                ]),
            'review' => $review,
            'progress' => [
                'period_start' => $progress['period_start'],
                'period_end' => $progress['period_end'],
                'seven_day' => $progress['seven_day'],
            ],
            'routine_day' => $projection,
            'module_summaries' => [
                'sleep' => $this->sleepStatistics->summarize($user, $dateValue, $dateValue, $dateValue),
                'routine_activities' => $projection['activity_summary'],
                'workouts' => $workouts['summary'],
                'nutrition' => $this->nutritionSummary->forDay($user, $dateValue),
            ],
        ]);
    }
}
