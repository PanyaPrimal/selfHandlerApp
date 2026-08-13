<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\PutFinanceOccurrenceOutcomeRequest;
use App\Http\Resources\Finance\FinancePlannedOccurrenceResource;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRule;
use App\Services\Finance\FinanceOccurrenceService;
use App\Services\RecurrenceMaterializer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FinanceOccurrenceController extends Controller
{
    public function __construct(
        private readonly FinanceOccurrenceService $occurrences,
        private readonly RecurrenceMaterializer $materializer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);
        $from = CarbonImmutable::parse($validated['from'], $request->user()->calendarTimezone());
        $to = CarbonImmutable::parse($validated['to'], $request->user()->calendarTimezone());
        $currentMonth = CarbonImmutable::now($request->user()->calendarTimezone())->startOfMonth();
        if ($from->lt($currentMonth) || $to->lt($from) || $from->diffInDays($to) > 365) {
            throw ValidationException::withMessages(['to' => __('messages.finance_range_invalid')]);
        }
        $this->materializer->materializeForUser($request->user(), $from->toDateString());
        $models = PlannedOccurrence::query()->ownedBy($request->user())
            ->whereIn('recurring_rule_id', RecurringRule::query()->ownedBy($request->user())
                ->whereIn('owner_type', [RecurringRule::OWNER_FINANCE_RECURRING_OPERATION,
                    RecurringRule::OWNER_FINANCE_DEBT, RecurringRule::OWNER_FINANCE_SAVING_FUND])->select('id'))
            ->where(function ($query) use ($from, $to): void {
                $query->where(function ($original) use ($from, $to): void {
                    $original->whereNull('rescheduled_to')
                        ->whereBetween('occurrence_date', [$from->toDateString(), $to->toDateString()]);
                })->orWhereBetween('rescheduled_to', [$from->toDateString(), $to->toDateString()]);
            })
            ->with($this->relations())
            ->orderByRaw('COALESCE(rescheduled_to, occurrence_date)')->orderBy('occurrence_time')->orderBy('id')->get();

        $data = FinancePlannedOccurrenceResource::collection($models)->resolve($request);
        $counts = ['total' => count($data), 'planned' => 0, 'actual' => 0, 'skipped' => 0,
            'overdue' => 0, 'unavailable' => 0, 'recurring_operation' => 0, 'debt' => 0, 'fund' => 0];
        foreach ($data as $row) {
            $counts[$row['status']]++;
            $counts[$row['context']['kind']]++;
        }

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'data' => $data,
            'counts' => $counts,
        ]);
    }

    public function put(PutFinanceOccurrenceOutcomeRequest $request, int $occurrence): JsonResponse
    {
        $model = PlannedOccurrence::query()->ownedBy($request->user())->findOrFail($occurrence);
        [, $created] = $this->occurrences->setOutcome($request->user(), $model, $request->validated('outcome'));

        return $this->one($model->fresh(), $request, $created ? 201 : 200);
    }

    public function clear(Request $request, int $occurrence): JsonResponse
    {
        $model = PlannedOccurrence::query()->ownedBy($request->user())->findOrFail($occurrence);
        $cleared = $this->occurrences->clearOutcome($request->user(), $model);

        return $this->one($cleared, $request);
    }

    private function one(PlannedOccurrence $occurrence, Request $request, int $status = 200): JsonResponse
    {
        $occurrence->load($this->relations());

        return response()->json([
            'data' => FinancePlannedOccurrenceResource::make($occurrence)->resolve($request),
        ], $status);
    }

    /** @return list<string> */
    private function relations(): array
    {
        return ['recurringRule', 'financeDetail.account', 'financeDetail.category',
            'financeOccurrenceFact.transactionGroup', 'financeDebtDetail',
            'financeDebtPaymentFact.transactionGroup.reversedBy', 'financeFundDetail',
            'financeFundOccurrenceFact.movement.reversedBy', 'financeFundOccurrenceFact.movement.transactionGroup',
            'financeFundOccurrenceFact.transactionGroup.reversedBy'];
    }
}
