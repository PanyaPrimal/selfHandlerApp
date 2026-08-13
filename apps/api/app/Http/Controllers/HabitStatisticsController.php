<?php

namespace App\Http\Controllers;

use App\Models\Habit;
use App\Services\HabitStatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HabitStatisticsController extends Controller
{
    public function __construct(private readonly HabitStatisticsService $statistics) {}

    public function show(Request $request, Habit $habit): JsonResponse
    {
        $user = $request->user();
        abort_unless($habit->isOwnedBy($user), 404);
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $today = CarbonImmutable::now($user->calendarTimezone())->toDateString();

        return response()->json([
            'data' => $this->statistics->calculate($habit, $data['from'], $data['to'], $today),
        ]);
    }
}
