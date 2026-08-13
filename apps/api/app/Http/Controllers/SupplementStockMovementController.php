<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupplementStockMovementRequest;
use App\Http\Resources\SupplementRestockProposalResource;
use App\Http\Resources\SupplementStockMovementResource;
use App\Models\Supplement;
use App\Models\SupplementStockMovement;
use App\Services\SupplementRestockProposalService;
use App\Services\SupplementStockForecastService;
use App\Services\SupplementStockMovementService;
use App\Services\SupplementStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplementStockMovementController extends Controller
{
    public function __construct(
        private readonly SupplementStockMovementService $movements,
        private readonly SupplementStockService $stock,
        private readonly SupplementStockForecastService $forecasts,
        private readonly SupplementRestockProposalService $proposals,
    ) {}

    public function index(Request $request, Supplement $supplement): JsonResponse
    {
        abort_unless($supplement->isOwnedBy($request->user()), 404);
        $movements = SupplementStockMovement::query()->ownedBy($request->user())
            ->where('supplement_id', $supplement->id)
            ->with('supplement')->orderByDesc('effective_on')->orderByDesc('id')->get();

        return response()->json([
            'data' => SupplementStockMovementResource::collection($movements)->resolve($request),
        ]);
    }

    public function store(StoreSupplementStockMovementRequest $request, Supplement $supplement): JsonResponse
    {
        abort_unless($supplement->isOwnedBy($request->user()), 404);
        $movement = $this->movements->create($supplement, $request->user(), $request->validated());
        $supplement->setRelation('user', $request->user());
        $stock = $this->stock->forSupplement($supplement);
        $forecast = $this->forecasts->forecast($supplement, $stock);
        $proposal = $this->proposals->reconcile($supplement, $forecast);
        unset($stock['has_facts']);

        return response()->json([
            'data' => SupplementStockMovementResource::make($movement->load('supplement'))->resolve($request),
            'stock' => $stock,
            'forecast' => $forecast,
            'restock_proposal' => $proposal
                ? SupplementRestockProposalResource::make($proposal)->resolve($request)
                : null,
        ], 201);
    }
}
