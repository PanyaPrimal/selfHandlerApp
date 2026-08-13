<?php

namespace App\Http\Controllers;

use App\Http\Resources\SleepPlanResource;
use App\Models\SleepPlan;
use App\Services\SleepStatisticsService;
use App\Services\SleepWorkspaceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SleepController extends Controller
{
    public function __construct(
        private readonly SleepWorkspaceService $workspace,
        private readonly SleepStatisticsService $statistics,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'state' => ['sometimes', Rule::in(['active', 'paused', 'archived'])],
        ]);
        $today = CarbonImmutable::now($user->calendarTimezone())->toDateString();
        $date = $validated['date'] ?? $today;
        $state = $validated['state'] ?? 'active';

        $plans = SleepPlan::query()
            ->ownedBy($user)
            ->when($state === 'active', fn ($query) => $query->where('is_archived', false)->where('is_active', true))
            ->when($state === 'paused', fn ($query) => $query->where('is_archived', false)->where('is_active', false))
            ->when($state === 'archived', fn ($query) => $query->where('is_archived', true))
            ->with('recurringRule.ruleWeekdays')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
        $this->workspace->attachSelectedNights($plans, $user, $date);

        $statistics = $this->statistics->summarize(
            $user,
            CarbonImmutable::parse($date)->subDays(89)->toDateString(),
            $date,
            $date,
        );
        unset($statistics['selected_night']);

        return response()->json([
            'date' => $date,
            'today' => $today,
            'data' => SleepPlanResource::collection($plans)->resolve($request),
            'statistics' => $statistics,
        ]);
    }
}
