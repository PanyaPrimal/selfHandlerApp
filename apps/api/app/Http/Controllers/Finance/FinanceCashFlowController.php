<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceCashFlowService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FinanceCashFlowController extends Controller
{
    public function __construct(private readonly FinanceCashFlowService $cashFlow) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate(['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $today = CarbonImmutable::now($request->user()->calendarTimezone())->startOfMonth()->format('Y-m');
        if ($validated['month'] < $today) {
            throw ValidationException::withMessages(['month' => __('messages.finance_cash_flow_future_only')]);
        }

        return response()->json(['data' => $this->cashFlow->build($request->user(), $validated['month'])]);
    }
}
