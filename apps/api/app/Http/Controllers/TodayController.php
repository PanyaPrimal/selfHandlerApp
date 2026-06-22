<?php

namespace App\Http\Controllers;

use App\Models\DailyReview;
use App\Models\Routine;
use App\Models\RoutineLog;
use App\Support\CurrentUser;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TodayController extends Controller
{
    public function __invoke(Request $request, CurrentUser $currentUser): JsonResponse
    {
        $user = $currentUser->resolve($request);
        $date = CarbonImmutable::parse($request->query('date', today()->toDateString()))->startOfDay();

        $routines = Routine::query()
            ->where('user_id', $user->id)
            ->with('goals')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->filter(fn (Routine $routine): bool => $routine->isScheduledFor($date))
            ->values();

        $logs = RoutineLog::query()
            ->where('user_id', $user->id)
            ->whereDate('log_date', $date)
            ->whereIn('routine_id', $routines->pluck('id'))
            ->get()
            ->keyBy('routine_id');

        $done = $logs->where('status', 'done')->count();
        $skipped = $logs->where('status', 'skipped')->count();
        $scheduled = $routines->count();

        $review = DailyReview::query()
            ->where('user_id', $user->id)
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
