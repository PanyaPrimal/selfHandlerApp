<?php

namespace App\Http\Controllers;

use App\Http\Requests\MealMutationRequest;
use App\Http\Resources\MealResource;
use App\Models\Meal;
use App\Services\MealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MealController extends Controller
{
    public function __construct(private readonly MealService $meals) {}

    public function store(MealMutationRequest $request): JsonResponse
    {
        $meal = $this->meals->create($request->user(), $request->validated());

        return response()->json(['data' => MealResource::make($meal)->resolve($request)], 201);
    }

    public function update(MealMutationRequest $request, Meal $meal): JsonResponse
    {
        $meal = $this->meals->update($meal, $request->user(), $request->validated());

        return response()->json(['data' => MealResource::make($meal)->resolve($request)]);
    }

    public function destroy(Request $request, Meal $meal): JsonResponse
    {
        $this->meals->delete($meal, $request->user());

        return response()->json(status: 204);
    }
}
