<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinanceSummaryRequest;
use App\Http\Resources\Finance\FinanceAccountResource;
use App\Services\Finance\FinanceSummaryService;
use Illuminate\Http\JsonResponse;

class FinanceSummaryController extends Controller
{
    public function __construct(private readonly FinanceSummaryService $summaries) {}

    public function show(FinanceSummaryRequest $request): JsonResponse
    {
        $period = $request->period();
        $summary = $this->summaries->build(
            $request->user(), $period['from'], $period['to'], $period['as_of'],
        );

        return response()->json(['data' => [
            'accounts' => FinanceAccountResource::collection($summary['accounts'])->resolve($request),
            'consolidated' => $summary['consolidated'],
            'actuals' => $summary['actuals'],
        ]]);
    }
}
