<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinanceSourceExpenseRequest;
use App\Services\Finance\FinanceSourceExpenseService;
use Illuminate\Http\JsonResponse;

class FinanceSourceExpenseController extends Controller
{
    public function __construct(private readonly FinanceSourceExpenseService $sources) {}

    public function store(StoreFinanceSourceExpenseRequest $request): JsonResponse
    {
        [$group, $created, $source] = $this->sources->post($request->user(), $request->validated());
        $group->loadMissing('reversedBy');

        return response()->json(['transaction_public_id' => $group->public_id, 'source' => $source,
            'reversed' => $group->reversedBy !== null], $created ? 201 : 200);
    }
}
