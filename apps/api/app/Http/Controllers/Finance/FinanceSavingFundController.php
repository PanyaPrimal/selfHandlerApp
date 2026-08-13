<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinanceFundMovementRequest;
use App\Http\Requests\Finance\StoreFinanceSavingFundRequest;
use App\Http\Requests\Finance\UpdateFinanceSavingFundRequest;
use App\Models\FinanceSavingFund;
use App\Services\Finance\FinanceFundMovementService;
use App\Services\Finance\FinanceFundProjectionService;
use App\Services\Finance\FinanceFundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceSavingFundController extends Controller
{
    public function __construct(private readonly FinanceFundService $funds,
        private readonly FinanceFundMovementService $movements, private readonly FinanceFundProjectionService $projections) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['month' => ['sometimes', 'date_format:Y-m'], 'archived' => ['sometimes', 'boolean']]);

        return response()->json(['data' => $this->funds->list($request->user(), $validated['month'] ?? null, $request->boolean('archived')),
            'fund_types' => FinanceSavingFund::TYPES, 'storage_modes' => FinanceSavingFund::STORAGE_MODES,
            'top_up_modes' => FinanceSavingFund::TOP_UP_MODES, 'target_modes' => FinanceSavingFund::TARGET_MODES]);
    }

    public function store(StoreFinanceSavingFundRequest $request): JsonResponse
    {
        $model = $this->funds->create($request->user(), $request->validated());

        return response()->json(['data' => $this->funds->one($request->user(), $model)], 201);
    }

    public function update(UpdateFinanceSavingFundRequest $request, int $fund): JsonResponse
    {
        $model = FinanceSavingFund::query()->ownedBy($request->user())->findOrFail($fund);
        $model = $this->funds->update($request->user(), $model, $request->validated());

        return response()->json(['data' => $this->funds->one($request->user(), $model)]);
    }

    public function movement(StoreFinanceFundMovementRequest $request, int $fund): JsonResponse
    {
        $model = FinanceSavingFund::query()->ownedBy($request->user())->findOrFail($fund);
        [$movement, $created] = $this->movements->move($request->user(), $model, $request->validated());

        return response()->json(['data' => $this->projections->movement($movement),
            'fund' => $this->funds->one($request->user(), $model)], $created ? 201 : 200);
    }
}
