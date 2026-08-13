<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpsertSupplementIntakeRequest;
use App\Http\Resources\SupplementOccurrenceResource;
use App\Http\Resources\SupplementRestockProposalResource;
use App\Models\PlannedOccurrence;
use App\Models\RecurringRuleSlot;
use App\Models\SupplementCourse;
use App\Services\SupplementIntakeService;
use App\Services\SupplementRestockProposalService;
use App\Services\SupplementStockForecastService;
use App\Services\SupplementStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SupplementIntakeController extends Controller
{
    public function __construct(
        private readonly SupplementIntakeService $intakes,
        private readonly SupplementStockService $stock,
        private readonly SupplementStockForecastService $forecasts,
        private readonly SupplementRestockProposalService $proposals,
    ) {}

    public function upsert(UpsertSupplementIntakeRequest $request, PlannedOccurrence $occurrence): JsonResponse
    {
        $result = $this->intakes->upsert($occurrence, $request->user(), $request->validated());
        $occurrence = $this->decorate($result['occurrence'], $request);
        $supplement = $occurrence->getAttribute('course_projection')->supplement;
        $stock = $this->stock->forSupplement($supplement);
        $forecast = $this->forecasts->forecast($supplement, $stock);
        $proposal = $this->proposals->reconcile($supplement, $forecast);
        unset($stock['has_facts']);

        return response()->json([
            'data' => SupplementOccurrenceResource::make($occurrence)->resolve($request),
            'stock' => $stock,
            'forecast' => $forecast,
            'restock_proposal' => $proposal
                ? SupplementRestockProposalResource::make($proposal)->resolve($request)
                : null,
        ], $result['created'] ? 201 : 200);
    }

    public function clear(Request $request, PlannedOccurrence $occurrence): Response
    {
        $this->intakes->clear($occurrence, $request->user());
        $occurrence = $this->decorate(
            $occurrence->fresh(['recurringRule', 'supplementIntake']),
            $request,
        );
        $supplement = $occurrence->getAttribute('course_projection')->supplement;
        $stock = $this->stock->forSupplement($supplement);
        $this->proposals->reconcile(
            $supplement,
            $this->forecasts->forecast($supplement, $stock),
        );

        return response()->noContent();
    }

    private function decorate(PlannedOccurrence $occurrence, Request $request): PlannedOccurrence
    {
        $rule = $occurrence->recurringRule;
        $course = SupplementCourse::query()->ownedBy($request->user())
            ->with('supplement')->findOrFail($rule->owner_id);
        $context = RecurringRuleSlot::query()
            ->where('recurring_rule_id', $rule->id)
            ->where('slot', $occurrence->slot)
            ->with('supplementDetail')
            ->first()?->supplementDetail?->intake_context ?? 'unspecified';
        $course->supplement->setRelation('user', $request->user());
        $occurrence->setAttribute('course_projection', $course);
        $occurrence->setAttribute('intake_context_projection', $context);

        return $occurrence;
    }
}
