<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinanceCategoryRequest;
use App\Http\Requests\Finance\UpdateFinanceCategoryRequest;
use App\Http\Resources\Finance\FinanceCategoryResource;
use App\Models\FinanceCategory;
use App\Services\Finance\FinanceCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinanceCategoryController extends Controller
{
    public function __construct(private readonly FinanceCategoryService $categories) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'direction' => ['sometimes', Rule::in(FinanceCategory::DIRECTIONS)],
            'include_archived' => ['sometimes', 'boolean'],
        ]);
        $this->categories->ensureStarters($request->user());
        $query = FinanceCategory::query()->ownedBy($request->user())
            ->withExists('entries')->orderBy('direction')->orderBy('parent_scope')->orderBy('id');
        if (isset($validated['direction'])) {
            $query->where('direction', $validated['direction']);
        }
        if (($validated['include_archived'] ?? true) === false) {
            $query->whereNull('archived_at');
        }

        return response()->json([
            'data' => FinanceCategoryResource::collection($query->get())->resolve($request),
        ]);
    }

    public function store(StoreFinanceCategoryRequest $request): JsonResponse
    {
        return response()->json([
            'data' => FinanceCategoryResource::make(
                $this->categories->create($request->user(), $request->validated()),
            )->resolve($request),
        ], 201);
    }

    public function update(UpdateFinanceCategoryRequest $request, int $category): JsonResponse
    {
        $model = FinanceCategory::query()->ownedBy($request->user())->findOrFail($category);

        return response()->json([
            'data' => FinanceCategoryResource::make(
                $this->categories->update($model, $request->user(), $request->validated()),
            )->resolve($request),
        ]);
    }
}
