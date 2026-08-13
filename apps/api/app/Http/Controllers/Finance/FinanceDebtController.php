<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinanceDebtPaymentRequest;
use App\Http\Requests\Finance\StoreFinanceDebtRequest;
use App\Http\Requests\Finance\UpdateFinanceDebtRequest;
use App\Models\FinanceDebt;
use App\Services\Finance\FinanceDebtPaymentService;
use App\Services\Finance\FinanceDebtProjectionService;
use App\Services\Finance\FinanceDebtService;
use App\Services\Finance\FinanceExchangeRateService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class FinanceDebtController extends Controller
{
    public function __construct(private readonly FinanceDebtService $debts,
        private readonly FinanceDebtPaymentService $payments, private readonly FinanceDebtProjectionService $projections,
        private readonly FinanceExchangeRateService $exchangeRates) {}

    public function index(Request $request): JsonResponse
    {
        $data = $this->debts->list($request->user(), $request->boolean('archived'));

        return response()->json(['data' => $data,
            'totals' => $this->totals($request, $data), 'directions' => FinanceDebt::DIRECTIONS,
            'repayment_modes' => FinanceDebt::REPAYMENT_MODES]);
    }

    public function store(StoreFinanceDebtRequest $request): JsonResponse
    {
        $model = $this->debts->create($request->user(), $request->validated());

        return response()->json(['data' => $this->debts->one($request->user(), $model)], 201);
    }

    public function update(UpdateFinanceDebtRequest $request, int $debt): JsonResponse
    {
        $model = FinanceDebt::query()->ownedBy($request->user())->findOrFail($debt);
        $model = $this->debts->update($request->user(), $model, $request->validated());

        return response()->json(['data' => $this->debts->one($request->user(), $model)]);
    }

    public function payment(StoreFinanceDebtPaymentRequest $request, int $debt): JsonResponse
    {
        $model = FinanceDebt::query()->ownedBy($request->user())->findOrFail($debt);
        [$payment, $created] = $this->payments->pay($request->user(), $model, $request->validated());

        return response()->json(['data' => $this->projections->payment($payment),
            'debt' => $this->debts->one($request->user(), $model)], $created ? 201 : 200);
    }

    /** @param Collection<int,array<string,mixed>> $data @return array<string,mixed> */
    private function totals(Request $request, Collection $data): array
    {
        $user = $request->user();
        $base = $user->ensureProfile()->base_currency;
        $today = CarbonImmutable::now($user->calendarTimezone())->toDateString();
        $grouped = $data->groupBy(fn (array $row): string => $row['direction'].'|'.$row['currency'])
            ->map(fn (Collection $rows): string => $rows->reduce(
                fn (string $sum, array $row): string => bcadd($sum, $row['remaining_amount'], 4),
                '0.0000',
            ));
        $needsRates = $grouped->keys()->contains(fn (string $key): bool => ! str_ends_with($key, '|'.$base));
        $catalog = $needsRates ? $this->exchangeRates->catalog($user, $today) : collect();
        $totals = ['owe' => '0.0000', 'owed_to_me' => '0.0000'];
        $missing = [];

        foreach ($grouped as $key => $amount) {
            [$direction, $currency] = explode('|', $key, 2);
            if (bccomp($amount, '0', 4) === 0) {
                continue;
            }
            $lookup = $this->exchangeRates->lookup($user, $currency, $base, $today, $catalog);
            if ($lookup === null) {
                $missing[] = $currency;

                continue;
            }
            $totals[$direction] = bcadd(
                $totals[$direction],
                $this->exchangeRates->convert($amount, $currency, $base, $lookup),
                4,
            );
        }

        $missing = array_values(array_unique($missing));
        sort($missing);

        return ['base_currency' => $base, 'complete' => $missing === [],
            'owe' => $missing === [] ? $totals['owe'] : null,
            'owed_to_me' => $missing === [] ? $totals['owed_to_me'] : null,
            'missing_currencies' => $missing];
    }
}
