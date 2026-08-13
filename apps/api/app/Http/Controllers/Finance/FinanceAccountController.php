<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ReconcileFinanceAccountRequest;
use App\Http\Requests\Finance\StoreFinanceAccountRequest;
use App\Http\Requests\Finance\UpdateFinanceAccountRequest;
use App\Http\Resources\Finance\FinanceAccountResource;
use App\Http\Resources\Finance\FinanceTransactionResource;
use App\Models\FinanceAccount;
use App\Services\Finance\FinanceAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceAccountController extends Controller
{
    public function __construct(
        private readonly FinanceAccountService $accounts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['include_archived' => ['sometimes', 'boolean']]);
        $accounts = $this->accounts->list($request->user(), ($validated['include_archived'] ?? true));

        return response()->json(['data' => FinanceAccountResource::collection($accounts)->resolve($request)]);
    }

    public function store(StoreFinanceAccountRequest $request): JsonResponse
    {
        return $this->one($this->accounts->create($request->user(), $request->validated()), $request, 201);
    }

    public function update(UpdateFinanceAccountRequest $request, int $account): JsonResponse
    {
        $model = FinanceAccount::query()->ownedBy($request->user())->findOrFail($account);

        return $this->one($this->accounts->update($model, $request->user(), $request->validated()), $request);
    }

    public function reconcile(ReconcileFinanceAccountRequest $request, int $account): JsonResponse
    {
        $model = FinanceAccount::query()->ownedBy($request->user())->findOrFail($account);
        $result = $this->accounts->reconcile($model, $request->user(), $request->validated());
        $result['account'] = $this->accounts->list($request->user(), true)->firstWhere('id', $result['account']->id);

        return response()->json([
            'data' => FinanceAccountResource::make($result['account'])->resolve($request),
            'transaction' => $result['transaction']
                ? FinanceTransactionResource::make($result['transaction'])->resolve($request)
                : null,
        ]);
    }

    private function one(FinanceAccount $account, Request $request, int $status = 200): JsonResponse
    {
        $account = $this->accounts->list($request->user(), true)->firstWhere('id', $account->id);

        return response()->json([
            'data' => FinanceAccountResource::make($account)->resolve($request),
        ], $status);
    }
}
