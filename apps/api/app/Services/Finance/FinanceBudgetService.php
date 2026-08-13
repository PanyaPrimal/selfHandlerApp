<?php

namespace App\Services\Finance;

use App\Models\Currency;
use App\Models\FinanceBudgetLimit;
use App\Models\FinanceCategory;
use App\Models\FinanceExchangeRate;
use App\Models\FinanceLedgerEntry;
use App\Models\User;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class FinanceBudgetService
{
    public function __construct(private readonly FinanceExchangeRateService $exchangeRates) {}

    /** @param array<string,mixed> $data */
    public function create(User $user, array $data): FinanceBudgetLimit
    {
        return DB::transaction(function () use ($user, $data): FinanceBudgetLimit {
            $attributes = $this->validatedAttributes($user, $data);
            $this->assertNoOverlap($user, $attributes['category_id'], $attributes['budget_month']);

            return FinanceBudgetLimit::query()->create(['user_id' => $user->id, ...$attributes]);
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function update(FinanceBudgetLimit $budget, User $user, array $data): FinanceBudgetLimit
    {
        abort_unless($budget->isOwnedBy($user), 404);

        return DB::transaction(function () use ($budget, $user, $data): FinanceBudgetLimit {
            $locked = FinanceBudgetLimit::query()->ownedBy($user)->whereKey($budget->id)
                ->lockForUpdate()->firstOrFail();
            $candidate = [
                'month' => array_key_exists('month', $data)
                    ? $data['month'] : $locked->budget_month->format('Y-m'),
                'category_id' => $data['category_id'] ?? $locked->category_id,
                'limit_amount' => $data['limit_amount'] ?? $locked->limit_amount,
                'currency' => $data['currency'] ?? $locked->currency_code,
            ];
            $attributes = $this->validatedAttributes($user, $candidate, $locked->category_id);
            $this->assertNoOverlap(
                $user,
                $attributes['category_id'],
                $attributes['budget_month'],
                $locked->id,
            );
            $locked->fill($attributes)->save();

            return $locked->fresh();
        }, 3);
    }

    public function delete(FinanceBudgetLimit $budget, User $user): void
    {
        abort_unless($budget->isOwnedBy($user), 404);
        $budget->delete();
    }

    /** @return Collection<int,array<string,mixed>> */
    public function forMonth(User $user, string $month): Collection
    {
        $monthDate = $this->monthDate($month);
        $budgets = FinanceBudgetLimit::query()->ownedBy($user)
            ->whereDate('budget_month', $monthDate)
            ->with('category')
            ->orderBy('category_id')
            ->orderBy('id')
            ->get();
        if ($budgets->isEmpty()) {
            return collect();
        }

        $categoryIds = $budgets->pluck('category_id');
        $children = FinanceCategory::query()->ownedBy($user)
            ->whereIn('parent_id', $categoryIds)->get(['id', 'parent_id'])->groupBy('parent_id');
        $from = $monthDate;
        $to = CarbonImmutable::parse($monthDate, $user->calendarTimezone())->endOfMonth()->toDateString();
        $catalog = $this->exchangeRates->catalog($user, $to);

        $scopes = $budgets->mapWithKeys(fn (FinanceBudgetLimit $budget): array => [
            $budget->id => collect([$budget->category_id])
                ->merge($children->get($budget->category_id, collect())->pluck('id'))
                ->map(fn ($id): int => (int) $id)->all(),
        ]);
        $entries = FinanceLedgerEntry::query()
            ->join('finance_transaction_groups as finance_groups', 'finance_groups.id', '=', 'finance_ledger_entries.transaction_group_id')
            ->where('finance_ledger_entries.user_id', $user->id)
            ->where('finance_groups.kind', 'expense')
            ->whereIn('finance_ledger_entries.category_id', $scopes->flatten()->unique()->all())
            ->whereBetween('finance_groups.occurred_on', [$from, $to])
            ->selectRaw('finance_ledger_entries.category_id, finance_groups.occurred_on, finance_ledger_entries.currency_code, SUM(finance_ledger_entries.delta_amount) AS total_delta')
            ->groupBy('finance_ledger_entries.category_id', 'finance_groups.occurred_on', 'finance_ledger_entries.currency_code')
            ->orderBy('finance_groups.occurred_on')->get();

        return $budgets->map(fn (FinanceBudgetLimit $budget): array => $this->project(
            $user,
            $budget,
            $entries->whereIn('category_id', $scopes->get($budget->id, []))->values(),
            $catalog,
        ));
    }

    /** @return array<string,mixed> */
    public function one(User $user, FinanceBudgetLimit $budget): array
    {
        return $this->forMonth($user, $budget->budget_month->format('Y-m'))
            ->firstWhere('id', $budget->id) ?? abort(404);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function validatedAttributes(User $user, array $data, ?int $allowedArchivedCategoryId = null): array
    {
        $category = FinanceCategory::query()->ownedBy($user)->whereKey($data['category_id'])
            ->lockForUpdate()->firstOrFail();
        if ($category->direction !== 'expense'
            || ($category->archived_at !== null && $category->id !== $allowedArchivedCategoryId)) {
            throw ValidationException::withMessages([
                'category_id' => __('messages.finance_budget_category_invalid'),
            ]);
        }
        $currency = strtoupper((string) $data['currency']);
        if (! Currency::query()->whereKey($currency)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['currency' => __('messages.finance_currency_invalid')]);
        }
        try {
            $amount = Money::of((string) $data['limit_amount'], $currency);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['limit_amount' => __('messages.finance_money_invalid')]);
        }
        if (bccomp($amount->amount(), '0', 4) <= 0) {
            throw ValidationException::withMessages(['limit_amount' => __('messages.finance_positive_money')]);
        }

        return [
            'category_id' => $category->id,
            'budget_month' => $this->monthDate((string) $data['month']),
            'limit_amount' => $amount->amount(),
            'currency_code' => $currency,
        ];
    }

    private function assertNoOverlap(User $user, int $categoryId, string $month, ?int $except = null): void
    {
        $category = FinanceCategory::query()->ownedBy($user)->findOrFail($categoryId);
        $scope = collect([$category->id, $category->parent_id])
            ->merge(FinanceCategory::query()->ownedBy($user)->where('parent_id', $category->id)->pluck('id'))
            ->filter()->map(fn ($id): int => (int) $id)->unique()->all();
        $query = FinanceBudgetLimit::query()->ownedBy($user)
            ->whereDate('budget_month', $month)->whereIn('category_id', $scope)->lockForUpdate();
        if ($except !== null) {
            $query->whereKeyNot($except);
        }
        if ($query->exists()) {
            throw new HttpResponseException(response()->json([
                'message' => __('messages.finance_budget_overlap'),
            ], 409));
        }
    }

    /**
     * @param  Collection<int, FinanceLedgerEntry>  $entries
     * @param  Collection<int, FinanceExchangeRate>  $catalog
     * @return array<string,mixed>
     */
    private function project(
        User $user,
        FinanceBudgetLimit $budget,
        Collection $entries,
        Collection $catalog,
    ): array {
        $actual = '0.0000';
        $missing = [];
        $conversions = [];
        foreach ($entries as $entry) {
            $signed = bcadd('0', (string) $entry->getAttribute('total_delta'), 4);
            if (bccomp($signed, '0', 4) === 0) {
                continue;
            }
            $date = (string) $entry->getAttribute('occurred_on');
            $currency = (string) $entry->currency_code;
            $lookup = $this->exchangeRates->lookup($user, $currency, $budget->currency_code, $date, $catalog);
            if (! $lookup) {
                $missing[] = $currency;

                continue;
            }
            $sourceExpense = bcsub('0', $signed, 4);
            $converted = $this->exchangeRates->convert($sourceExpense, $currency, $budget->currency_code, $lookup);
            $actual = bcadd($actual, $converted, 4);
            $conversions[] = [
                'on' => $date,
                'from_currency' => $currency,
                'source_amount' => $sourceExpense,
                'converted_amount' => $converted,
                'rate' => $lookup['rate'],
                'rate_date' => $lookup['date'],
                'rate_direction' => $lookup['direction'],
            ];
        }
        $missing = array_values(array_unique($missing));
        sort($missing);
        $complete = $missing === [];
        $utilization = $complete ? $this->percentage($actual, (string) $budget->limit_amount) : null;
        $state = match (true) {
            ! $complete => null,
            bccomp($utilization, '80.0000', 4) < 0 => 'within',
            bccomp($utilization, '100.0000', 4) <= 0 => 'approaching',
            default => 'exceeded',
        };

        return [
            'id' => $budget->id,
            'month' => $budget->budget_month->format('Y-m'),
            'category' => $this->categorySummary($budget->category),
            'limit_amount' => (string) $budget->limit_amount,
            'currency' => $budget->currency_code,
            'complete' => $complete,
            'actual_amount' => $complete ? $actual : null,
            'remaining_amount' => $complete ? bcsub((string) $budget->limit_amount, $actual, 4) : null,
            'utilization_percent' => $utilization,
            'state' => $state,
            'missing_currencies' => $missing,
            'conversions' => $conversions,
            'created_at' => $budget->created_at?->toISOString(),
            'updated_at' => $budget->updated_at?->toISOString(),
        ];
    }

    /** @return array<string,mixed> */
    private function categorySummary(FinanceCategory $category): array
    {
        return [
            'id' => $category->id,
            'parent_id' => $category->parent_id,
            'label' => $category->displayLabel(),
            'archived' => $category->archived_at !== null,
        ];
    }

    private function monthDate(string $month): string
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw ValidationException::withMessages(['month' => __('messages.finance_month_invalid')]);
        }

        return $month.'-01';
    }

    private function percentage(string $amount, string $limit): string
    {
        $value = bcmul(bcdiv($amount, $limit, 8), '100', 8);
        $increment = bccomp($value, '0', 8) < 0 ? '-0.00005' : '0.00005';

        return bcadd(bcadd($value, $increment, 8), '0', 4);
    }
}
