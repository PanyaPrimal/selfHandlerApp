<?php

namespace App\Http\Controllers;

use App\Models\DailyReview;
use App\Models\Routine;
use App\Models\RoutineLog;
use App\Services\RoutineScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TodayController extends Controller
{
    public function __construct(private readonly RoutineScheduleService $scheduleService) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $timezone = config('selfhandler.timezone');
        $date = isset($validated['date'])
            ? CarbonImmutable::parse($validated['date'], $timezone)->startOfDay()
            : CarbonImmutable::now($timezone)->startOfDay();
        $isHistoricalDate = $date->isBefore(CarbonImmutable::now($timezone)->startOfDay());

        $logs = RoutineLog::query()
            ->ownedBy($user)
            ->where('log_date', $date->toDateString())
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
                'scheduleWeekdays',
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->filter(fn (Routine $routine): bool => $this->scheduleService->isScheduledFor($routine, $date)
                || ($isHistoricalDate && $logs->has($routine->id)))
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
                'preferred_time' => $routine->preferred_time,
                'sort_order' => $routine->sort_order,
                'is_active' => $routine->is_active,
                'is_archived' => $routine->is_archived,
                'log' => $logs->get($routine->id),
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
        ]);
    }
}
