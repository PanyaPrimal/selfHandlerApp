<?php

namespace App\Http\Controllers;

use App\Http\Resources\MealResource;
use App\Http\Resources\NutritionTargetResource;
use App\Models\Meal;
use App\Services\NutritionSummaryService;
use App\Services\NutritionTargetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NutritionDayController extends Controller
{
    public function __construct(
        private readonly NutritionTargetService $targets,
        private readonly NutritionSummaryService $summaries,
    ) {}

    public function show(Request $request, string $date): JsonResponse
    {
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
        $user = $request->user();
        $target = $this->targets->forDate($user, $date);
        $meals = Meal::query()->ownedBy($user)->whereDate('consumed_on', $date)->with(['entries', 'attachments'])
            ->orderByRaw('CASE WHEN consumed_at_local IS NULL THEN 1 ELSE 0 END')
            ->orderBy('consumed_at_local')->orderBy('id')->get();

        return response()->json(['data' => [
            'date' => $date,
            'meals' => MealResource::collection($meals)->resolve($request),
            'target' => NutritionTargetResource::make($target)->resolve($request),
            'refinement' => $this->targets->refinement($user, $target),
            'summary' => $this->summaries->forDay($user, $date),
        ]]);
    }

    public function summary(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d'],
        ]);
        $validator->after(function ($validator) use ($request): void {
            if (array_diff(array_keys($request->query()), ['from', 'to']) !== []) {
                $validator->errors()->add('request', __('messages.unknown_fields'));
            }
        });
        $data = $validator->validate();

        return response()->json(['data' => [
            'from' => $data['from'], 'to' => $data['to'],
            'days' => $this->summaries->forRange($request->user(), $data['from'], $data['to']),
        ]]);
    }
}
