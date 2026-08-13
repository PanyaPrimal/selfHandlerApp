<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReplaceHabitLimitStepsRequest;
use App\Models\Habit;
use App\Services\HabitLimitService;
use Illuminate\Http\JsonResponse;

class HabitLimitController extends Controller
{
    public function __construct(
        private readonly HabitLimitService $limits,
        private readonly HabitController $habits,
    ) {}

    public function replace(ReplaceHabitLimitStepsRequest $request, Habit $habit): JsonResponse
    {
        abort_unless($habit->isOwnedBy($request->user()), 404);
        $this->limits->replace($habit, $request->user(), $request->validated('steps'));

        return $this->habits->one($habit, $request);
    }
}
