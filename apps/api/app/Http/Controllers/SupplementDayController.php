<?php

namespace App\Http\Controllers;

use App\Http\Resources\SupplementDayResource;
use App\Services\SupplementAdherenceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SupplementDayController extends Controller
{
    public function __construct(private readonly SupplementAdherenceService $adherence) {}

    public function show(Request $request, string $date): JsonResponse
    {
        $date = Validator::make(['date' => $date], [
            'date' => ['required', 'date_format:Y-m-d'],
        ])->validate()['date'];
        $user = $request->user();

        return response()->json(['data' => SupplementDayResource::make([
            'date' => $date,
            'today' => CarbonImmutable::now($user->calendarTimezone())->toDateString(),
            'occurrences' => $this->adherence->occurrencesForDay($user, $date),
            'summary' => $this->adherence->forDay($user, $date),
        ])->resolve($request)]);
    }

    public function adherence(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json([
            'data' => $this->adherence->forRange($request->user(), $data['from'], $data['to']),
        ]);
    }
}
