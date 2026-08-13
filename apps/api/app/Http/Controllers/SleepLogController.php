<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpsertSleepLogRequest;
use App\Http\Resources\SleepPlanResource;
use App\Models\SleepPlan;
use App\Services\SleepLogService;
use App\Services\SleepWorkspaceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class SleepLogController extends Controller
{
    public function __construct(
        private readonly SleepLogService $logs,
        private readonly SleepWorkspaceService $workspace,
    ) {}

    public function upsert(UpsertSleepLogRequest $request, SleepPlan $sleepPlan, string $date): JsonResponse
    {
        $user = $request->user();
        abort_unless($sleepPlan->isOwnedBy($user), 404);
        $date = $this->date($date, $user->calendarTimezone());
        $this->logs->upsert($sleepPlan, $user, $date, $request->validated());

        return response()->json(['data' => $this->resource($sleepPlan, $user, $date)]);
    }

    public function clear(Request $request, SleepPlan $sleepPlan, string $date): Response
    {
        $user = $request->user();
        abort_unless($sleepPlan->isOwnedBy($user), 404);
        $date = $this->date($date, $user->calendarTimezone());
        $this->logs->clear($sleepPlan, $user, $date);

        return response()->noContent();
    }

    private function resource(SleepPlan $plan, $user, string $date): array
    {
        $plan = $plan->fresh('recurringRule.ruleWeekdays');
        $this->workspace->attachSelectedNights(collect([$plan]), $user, $date);

        return (new SleepPlanResource($plan))->resolve();
    }

    private function date(string $date, string $timezone): string
    {
        $value = Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])
            ->validate()['date'];

        return CarbonImmutable::parse($value, $timezone)->toDateString();
    }
}
