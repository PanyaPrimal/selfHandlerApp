<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinanceCounterpartyRequest;
use App\Http\Requests\Finance\UpdateFinanceCounterpartyRequest;
use App\Models\FinanceCounterparty;
use App\Services\Finance\FinanceCounterpartyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceCounterpartyController extends Controller
{
    public function __construct(private readonly FinanceCounterpartyService $counterparties) {}

    public function index(Request $request): JsonResponse
    {
        $data = $this->counterparties->list($request->user(), $request->boolean('archived'));

        return response()->json(['data' => $data->map(fn ($model) => $this->serialize($model))->values(),
            'kinds' => FinanceCounterparty::KINDS]);
    }

    public function store(StoreFinanceCounterpartyRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->serialize($this->counterparties->create($request->user(), $request->validated()))], 201);
    }

    public function update(UpdateFinanceCounterpartyRequest $request, int $counterparty): JsonResponse
    {
        $model = FinanceCounterparty::query()->ownedBy($request->user())->findOrFail($counterparty);

        return response()->json(['data' => $this->serialize($this->counterparties->update($request->user(), $model, $request->validated()))]);
    }

    /** @return array<string,mixed> */
    private function serialize(FinanceCounterparty $model): array
    {
        return ['id' => $model->id, 'name' => $model->name, 'kind' => $model->kind, 'note' => $model->note,
            'archived' => $model->is_archived, 'created_at' => $model->created_at?->toISOString(),
            'updated_at' => $model->updated_at?->toISOString()];
    }
}
