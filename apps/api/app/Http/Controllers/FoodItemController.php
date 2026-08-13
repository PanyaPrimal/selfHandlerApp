<?php

namespace App\Http\Controllers;

use App\Http\Requests\FoodItemMutationRequest;
use App\Http\Resources\FoodItemResource;
use App\Models\FoodItem;
use App\Services\FoodCatalogueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FoodItemController extends Controller
{
    public function __construct(private readonly FoodCatalogueService $foods) {}

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), ['state' => ['sometimes', Rule::in(['active', 'archived', 'all'])]]);
        $validator->after(function ($validator) use ($request): void {
            if (array_diff(array_keys($request->query()), ['state']) !== []) {
                $validator->errors()->add('request', __('messages.unknown_fields'));
            }
        });
        $data = $validator->validate();

        return response()->json([
            'data' => FoodItemResource::collection($this->foods->list($request->user(), $data['state'] ?? 'active'))->resolve($request),
        ]);
    }

    public function store(FoodItemMutationRequest $request): JsonResponse
    {
        $food = $this->foods->create($request->user(), $request->validated());

        return response()->json(['data' => FoodItemResource::make($food)->resolve($request)], 201);
    }

    public function update(FoodItemMutationRequest $request, FoodItem $food): JsonResponse
    {
        $food = $this->foods->update($food, $request->user(), $request->validated());

        return response()->json(['data' => FoodItemResource::make($food)->resolve($request)]);
    }
}
