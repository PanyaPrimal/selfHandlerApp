<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinanceTransferRequest;
use App\Http\Resources\Finance\FinanceTransactionResource;
use App\Services\Finance\FinanceLedgerService;
use Illuminate\Http\JsonResponse;

class FinanceTransferController extends Controller
{
    public function __construct(private readonly FinanceLedgerService $ledger) {}

    public function store(StoreFinanceTransferRequest $request): JsonResponse
    {
        [$group, $created] = $this->ledger->transfer($request->user(), $request->validated());

        return response()->json([
            'data' => FinanceTransactionResource::make($group)->resolve($request),
        ], $created ? 201 : 200);
    }
}
