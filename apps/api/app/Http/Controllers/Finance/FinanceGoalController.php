<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinanceGoalRequest;
use App\Http\Requests\Finance\UpdateFinanceGoalRequest;
use App\Models\FinanceGoalDetail;
use App\Models\Goal;
use App\Services\Finance\FinanceGoalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceGoalController extends Controller
{
    public function __construct(private readonly FinanceGoalService $goals) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->goals->list($request->user(), $request->boolean('archived')),
            'kinds' => FinanceGoalDetail::KINDS]);
    }

    public function store(StoreFinanceGoalRequest $request): JsonResponse
    {
        $goal = $this->goals->create($request->user(), $request->validated());

        return response()->json(['data' => $this->goals->one($request->user(), $goal)], 201);
    }

    public function update(UpdateFinanceGoalRequest $request, int $goal): JsonResponse
    {
        $model = Goal::query()->ownedBy($request->user())->where('type', Goal::TYPE_FINANCE)->findOrFail($goal);
        $model = $this->goals->update($request->user(), $model, $request->validated());

        return response()->json(['data' => $this->goals->one($request->user(), $model)]);
    }
}
