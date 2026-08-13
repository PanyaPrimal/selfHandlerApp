<?php

namespace App\Http\Controllers;

use App\Services\SleepStatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SleepStatisticsController extends Controller
{
    public function __construct(private readonly SleepStatisticsService $statistics) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json([
            'data' => $this->statistics->summarize($request->user(), $data['from'], $data['to']),
        ]);
    }
}
