<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReplaceRoutineDaySelectionsRequest;
use App\Services\RoutineDayProjectionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class RoutineDaySelectionController extends Controller
{
    public function __construct(private readonly RoutineDayProjectionService $projection) {}

    public function replace(ReplaceRoutineDaySelectionsRequest $request, string $date): JsonResponse
    {
        $user = $request->user();
        $value = Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])
            ->validate()['date'];
        $date = CarbonImmutable::parse($value, $user->calendarTimezone())->toDateString();
        $data = $this->projection->replace($user, $date, $request->validated());
        unset($data['anytime']);

        return response()->json(['data' => $data]);
    }
}
