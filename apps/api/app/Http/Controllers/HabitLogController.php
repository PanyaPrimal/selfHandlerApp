<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpsertHabitLogRequest;
use App\Models\Habit;
use App\Services\HabitLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class HabitLogController extends Controller
{
    public function __construct(
        private readonly HabitLogService $logs,
        private readonly HabitController $habits,
    ) {}

    public function upsert(UpsertHabitLogRequest $request, Habit $habit, string $date): JsonResponse
    {
        $this->validatedDate($date);
        abort_unless($habit->isOwnedBy($request->user()), 404);
        $this->logs->upsert($habit, $request->user(), $date, $request->validated());

        return $this->habits->one($habit, $request, date: $date);
    }

    public function clear(Request $request, Habit $habit, string $date): Response
    {
        $this->validatedDate($date);
        abort_unless($habit->isOwnedBy($request->user()), 404);
        $this->logs->clear($habit, $request->user(), $date);

        return response()->noContent();
    }

    private function validatedDate(string $date): void
    {
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
    }
}
