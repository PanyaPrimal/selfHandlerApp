<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupplementRequest;
use App\Http\Requests\UpdateSupplementRequest;
use App\Http\Resources\SupplementResource;
use App\Models\Supplement;
use App\Models\User;
use App\Services\SupplementRestockProposalService;
use App\Services\SupplementService;
use App\Services\SupplementStockForecastService;
use App\Services\SupplementStockService;
use App\ValueObjects\SupplementQuantity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SupplementController extends Controller
{
    public function __construct(
        private readonly SupplementService $supplements,
        private readonly SupplementStockService $stock,
        private readonly SupplementStockForecastService $forecasts,
        private readonly SupplementRestockProposalService $proposals,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $state = $request->validate(['state' => ['sometimes', 'in:active,archived,all']])['state'] ?? 'active';
        $query = Supplement::query()->ownedBy($request->user())->orderBy('name')->orderBy('id');
        if ($state !== 'all') {
            $query->where('is_archived', $state === 'archived');
        }
        $supplements = $query->get();
        $this->decorate($supplements, $request->user());

        return response()->json([
            'data' => SupplementResource::collection($supplements)->resolve($request),
            'meta' => [
                'categories' => Supplement::CATEGORIES,
                'forms' => Supplement::FORMS,
                'canonical_units' => SupplementQuantity::STOCK_UNITS,
                'display_units' => SupplementQuantity::DISPLAY_UNITS,
            ],
        ]);
    }

    public function store(StoreSupplementRequest $request): JsonResponse
    {
        $supplement = $this->supplements->create($request->user(), $request->validated());

        return $this->one($supplement, $request, 201);
    }

    public function update(UpdateSupplementRequest $request, Supplement $supplement): JsonResponse
    {
        $supplement = $this->supplements->update($supplement, $request->user(), $request->validated());

        return $this->one($supplement, $request);
    }

    public function one(Supplement $supplement, Request $request, int $status = 200): JsonResponse
    {
        abort_unless($supplement->isOwnedBy($request->user()), 404);
        $collection = $supplement->newCollection([$supplement]);
        $this->decorate($collection, $request->user());

        return response()->json([
            'data' => SupplementResource::make($collection->first())->resolve($request),
        ], $status);
    }

    /** @param Collection<int, Supplement> $supplements */
    private function decorate(Collection $supplements, User $user): void
    {
        $stock = $this->stock->forMany($supplements);
        foreach ($supplements as $supplement) {
            $supplement->setRelation('user', $user);
        }
        $forecasts = $this->forecasts->forecastMany($supplements, $stock);
        foreach ($supplements as $supplement) {
            $forecast = $forecasts[$supplement->id];
            $proposal = $this->proposals->reconcile($supplement, $forecast);
            $supplement->setAttribute('stock_projection', $stock[$supplement->id]);
            $supplement->setAttribute('forecast_projection', $forecast);
            $supplement->setAttribute('proposal_projection', $proposal);
        }
    }
}
