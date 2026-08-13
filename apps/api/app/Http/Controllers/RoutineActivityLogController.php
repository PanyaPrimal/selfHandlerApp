<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpsertRoutineActivityLogRequest;
use App\Models\Routine;
use App\Models\RoutineActivity;
use App\Services\RoutineActivityLogService;
use App\Services\RoutineTemplatePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RoutineActivityLogController extends Controller
{
    public function __construct(
        private readonly RoutineActivityLogService $logs,
        private readonly RoutineTemplatePresenter $presenter,
    ) {}

    public function upsert(
        UpsertRoutineActivityLogRequest $request,
        Routine $routine,
        RoutineActivity $activity,
        string $date,
    ): JsonResponse {
        $user = $request->user();
        $date = $this->date($date, $user->calendarTimezone());
        $this->logs->upsert($routine, $activity, $user, $date, $request->validated());

        return response()->json(['data' => $this->presenter->payload($routine->fresh(), $user, $date)]);
    }

    public function clear(
        Request $request,
        Routine $routine,
        RoutineActivity $activity,
        string $date,
    ): JsonResponse {
        $user = $request->user();
        $date = $this->date($date, $user->calendarTimezone());
        $this->logs->clear($routine, $activity, $user, $date);

        return response()->json(['data' => $this->presenter->payload($routine->fresh(), $user, $date)]);
    }

    private function date(string $date, string $timezone): string
    {
        $value = Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])
            ->validate()['date'];

        return CarbonImmutable::parse($value, $timezone)->toDateString();
    }
}
