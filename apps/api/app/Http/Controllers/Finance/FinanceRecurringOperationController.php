<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinanceRecurringOperationRequest;
use App\Http\Requests\Finance\UpdateFinanceRecurringOperationRequest;
use App\Http\Resources\Finance\FinanceRecurringOperationResource;
use App\Models\FinanceRecurringOperation;
use App\Services\Finance\FinanceRecurringOperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceRecurringOperationController extends Controller
{
    public function __construct(private readonly FinanceRecurringOperationService $operations) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['include_archived' => ['sometimes', 'boolean']]);
        $query = FinanceRecurringOperation::query()->ownedBy($request->user())
            ->with(['account', 'category', 'recurringRule.ruleMonthdays'])
            ->orderBy('name')->orderBy('id');
        if (($validated['include_archived'] ?? true) === false) {
            $query->where('is_archived', false);
        }

        return response()->json([
            'data' => FinanceRecurringOperationResource::collection($query->get())->resolve($request),
        ]);
    }

    public function store(StoreFinanceRecurringOperationRequest $request): JsonResponse
    {
        $operation = $this->operations->create($request->user(), $request->validated());

        return response()->json([
            'data' => FinanceRecurringOperationResource::make($operation)->resolve($request),
        ], 201);
    }

    public function update(UpdateFinanceRecurringOperationRequest $request, int $operation): JsonResponse
    {
        $model = FinanceRecurringOperation::query()->ownedBy($request->user())->findOrFail($operation);
        $updated = $this->operations->update($model, $request->user(), $request->validated());

        return response()->json([
            'data' => FinanceRecurringOperationResource::make($updated)->resolve($request),
        ]);
    }
}
