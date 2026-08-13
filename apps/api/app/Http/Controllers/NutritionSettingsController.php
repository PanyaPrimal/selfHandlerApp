<?php

namespace App\Http\Controllers;

use App\Http\Requests\NutritionSettingsRequest;
use App\Http\Resources\NutritionSettingsResource;
use App\Services\NutritionSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NutritionSettingsController extends Controller
{
    public function __construct(private readonly NutritionSettingsService $settings) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => NutritionSettingsResource::make($this->settings->get($request->user()))->resolve($request),
        ]);
    }

    public function replace(NutritionSettingsRequest $request): JsonResponse
    {
        $settings = $this->settings->update($request->user(), $request->validated());

        return response()->json(['data' => NutritionSettingsResource::make($settings)->resolve($request)]);
    }
}
