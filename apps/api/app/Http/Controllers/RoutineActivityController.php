<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReplaceRoutineActivitiesRequest;
use App\Models\Routine;
use App\Services\RoutineActivityService;
use App\Services\RoutineTemplatePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class RoutineActivityController extends Controller
{
    public function __construct(
        private readonly RoutineActivityService $activities,
        private readonly RoutineTemplatePresenter $presenter,
    ) {}

    public function replace(ReplaceRoutineActivitiesRequest $request, Routine $routine): JsonResponse
    {
        $user = $request->user();
        abort_unless($routine->isOwnedBy($user), 404);
        $this->activities->replace($routine, $user, $request->validated('activities'));
        $date = CarbonImmutable::now($user->calendarTimezone())->toDateString();

        return response()->json(['data' => $this->presenter->payload($routine->fresh(), $user, $date)]);
    }
}
