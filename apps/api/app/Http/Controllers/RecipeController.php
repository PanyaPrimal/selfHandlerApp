<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecipeMutationRequest;
use App\Http\Resources\RecipeResource;
use App\Models\Recipe;
use App\Services\RecipeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RecipeController extends Controller
{
    public function __construct(private readonly RecipeService $recipes) {}

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
            'data' => RecipeResource::collection($this->recipes->list($request->user(), $data['state'] ?? 'active'))->resolve($request),
        ]);
    }

    public function store(RecipeMutationRequest $request): JsonResponse
    {
        $recipe = $this->recipes->create($request->user(), $request->validated());

        return response()->json(['data' => RecipeResource::make($recipe)->resolve($request)], 201);
    }

    public function update(RecipeMutationRequest $request, Recipe $recipe): JsonResponse
    {
        $recipe = $this->recipes->update($recipe, $request->user(), $request->validated());

        return response()->json(['data' => RecipeResource::make($recipe)->resolve($request)]);
    }
}
