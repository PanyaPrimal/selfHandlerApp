<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ReverseFinanceTransactionRequest;
use App\Http\Requests\Finance\StoreFinanceTransactionRequest;
use App\Http\Resources\Finance\FinanceTransactionResource;
use App\Models\FinanceAccount;
use App\Models\FinanceTransactionGroup;
use App\Services\Finance\FinanceLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FinanceTransactionController extends Controller
{
    public function __construct(private readonly FinanceLedgerService $ledger) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'account_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        $today = CarbonImmutable::now($request->user()->calendarTimezone())->startOfDay();
        $timezone = $request->user()->calendarTimezone();
        $from = CarbonImmutable::parse($validated['from'] ?? $today->subDays(365)->toDateString(), $timezone)->startOfDay();
        $to = CarbonImmutable::parse($validated['to'] ?? $today->toDateString(), $timezone)->startOfDay();
        if ($from->greaterThan($to) || $from->diffInDays($to) > 365 || $to->greaterThan($today)) {
            throw ValidationException::withMessages(['from' => __('messages.finance_range_invalid')]);
        }

        $query = FinanceTransactionGroup::query()->ownedBy($request->user())
            ->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()])
            ->with(['entries.account', 'entries.category', 'reverses', 'reversedBy'])
            ->orderByDesc('occurred_on')->orderByDesc('id')->limit(500);
        if (isset($validated['account_id'])) {
            $account = FinanceAccount::query()->ownedBy($request->user())->findOrFail($validated['account_id']);
            $query->whereHas('entries', fn ($entries) => $entries->where('account_id', $account->id));
        }

        return response()->json([
            'data' => FinanceTransactionResource::collection($query->get())->resolve($request),
        ]);
    }

    public function store(StoreFinanceTransactionRequest $request): JsonResponse
    {
        [$group, $created] = $this->ledger->postActual($request->user(), $request->validated());

        return response()->json([
            'data' => FinanceTransactionResource::make($group)->resolve($request),
        ], $created ? 201 : 200);
    }

    public function reverse(ReverseFinanceTransactionRequest $request, string $transaction): JsonResponse
    {
        $original = FinanceTransactionGroup::query()->ownedBy($request->user())
            ->where('public_id', $transaction)->firstOrFail();
        [$group] = $this->ledger->reverse($request->user(), $original, $request->validated());

        return response()->json([
            'data' => FinanceTransactionResource::make($group)->resolve($request),
        ], 201);
    }
}
