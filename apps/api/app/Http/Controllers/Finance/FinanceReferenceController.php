<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\UpsertFinanceExchangeRateRequest;
use App\Http\Resources\Finance\FinanceExchangeRateResource;
use App\Models\Currency;
use App\Models\FinanceExchangeRate;
use App\Services\Finance\FinanceExchangeRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinanceReferenceController extends Controller
{
    public function __construct(private readonly FinanceExchangeRateService $rates) {}

    public function currencies(): JsonResponse
    {
        return response()->json(['data' => Currency::query()->orderBy('code')->get()->map(fn (Currency $currency): array => [
            'code' => $currency->code,
            'decimal_places' => $currency->decimal_places,
            'active' => $currency->is_active,
        ])->all()]);
    }

    public function rates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_currency' => ['sometimes', Rule::exists('currencies', 'code')],
            'to_currency' => ['sometimes', Rule::exists('currencies', 'code')],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        if (isset($validated['from'], $validated['to']) && $validated['from'] > $validated['to']) {
            throw ValidationException::withMessages(['from' => __('messages.finance_range_invalid')]);
        }
        $query = FinanceExchangeRate::query()->ownedBy($request->user())->orderByDesc('rate_date')->orderByDesc('id');
        foreach (['from_currency', 'to_currency'] as $field) {
            if (isset($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }
        if (isset($validated['from'])) {
            $query->whereDate('rate_date', '>=', $validated['from']);
        }
        if (isset($validated['to'])) {
            $query->whereDate('rate_date', '<=', $validated['to']);
        }

        return response()->json(['data' => FinanceExchangeRateResource::collection($query->limit(500)->get())->resolve($request)]);
    }

    public function upsert(UpsertFinanceExchangeRateRequest $request): JsonResponse
    {
        [$rate, $created] = $this->rates->upsert($request->user(), $request->validated());

        return response()->json([
            'data' => FinanceExchangeRateResource::make($rate)->resolve($request),
        ], $created ? 201 : 200);
    }
}
