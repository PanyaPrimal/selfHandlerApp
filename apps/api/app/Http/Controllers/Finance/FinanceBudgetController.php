<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinanceBudgetRequest;
use App\Http\Requests\Finance\UpdateFinanceBudgetRequest;
use App\Models\FinanceBudgetLimit;
use App\Services\Finance\FinanceBudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FinanceBudgetController extends Controller
{
    public function __construct(private readonly FinanceBudgetService $budgets) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);

        return response()->json([
            'month' => $validated['month'],
            'data' => $this->budgets->forMonth($request->user(), $validated['month'])->all(),
        ]);
    }

    public function store(StoreFinanceBudgetRequest $request): JsonResponse
    {
        $budget = $this->budgets->create($request->user(), $request->validated());

        return response()->json(['data' => $this->budgets->one($request->user(), $budget)], 201);
    }

    public function update(UpdateFinanceBudgetRequest $request, int $budget): JsonResponse
    {
        $model = FinanceBudgetLimit::query()->ownedBy($request->user())->findOrFail($budget);
        $updated = $this->budgets->update($model, $request->user(), $request->validated());

        return response()->json(['data' => $this->budgets->one($request->user(), $updated)]);
    }

    public function destroy(Request $request, int $budget): Response
    {
        $model = FinanceBudgetLimit::query()->ownedBy($request->user())->findOrFail($budget);
        $this->budgets->delete($model, $request->user());

        return response()->noContent();
    }
}
