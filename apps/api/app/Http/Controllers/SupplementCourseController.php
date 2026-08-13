<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupplementCourseRequest;
use App\Http\Requests\UpdateSupplementCourseRequest;
use App\Http\Resources\SupplementCourseResource;
use App\Models\SupplementCourse;
use App\Models\SupplementCourseSlot;
use App\Services\SupplementCourseService;
use App\Services\SupplementRestockProposalService;
use App\Services\SupplementStockForecastService;
use App\Services\SupplementStockService;
use App\ValueObjects\WeekdayCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplementCourseController extends Controller
{
    public function __construct(
        private readonly SupplementCourseService $courses,
        private readonly SupplementStockService $stock,
        private readonly SupplementStockForecastService $forecasts,
        private readonly SupplementRestockProposalService $proposals,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $state = $request->validate(['state' => ['sometimes', 'in:active,archived,all']])['state'] ?? 'active';
        $query = SupplementCourse::query()->ownedBy($request->user())
            ->with($this->relations())->orderBy('starts_on')->orderBy('id');
        if ($state !== 'all') {
            $query->where('is_archived', $state === 'archived');
        }

        return response()->json([
            'data' => SupplementCourseResource::collection($query->get())->resolve($request),
            'meta' => [
                'frequencies' => ['daily', 'weekly'],
                'weekdays' => WeekdayCode::values(),
                'intake_contexts' => SupplementCourseSlot::CONTEXTS,
                'max_slots' => 8,
            ],
        ]);
    }

    public function store(StoreSupplementCourseRequest $request): JsonResponse
    {
        $course = $this->courses->create($request->user(), $request->validated());
        $this->reconcileStock($course, $request);

        return $this->one($course, $request, 201);
    }

    public function update(UpdateSupplementCourseRequest $request, SupplementCourse $course): JsonResponse
    {
        $course = $this->courses->update($course, $request->user(), $request->validated());
        $this->reconcileStock($course, $request);

        return $this->one($course, $request);
    }

    private function one(SupplementCourse $course, Request $request, int $status = 200): JsonResponse
    {
        abort_unless($course->isOwnedBy($request->user()), 404);
        $course->load($this->relations());

        return response()->json([
            'data' => SupplementCourseResource::make($course)->resolve($request),
        ], $status);
    }

    /** @return list<string> */
    private function relations(): array
    {
        return ['supplement', 'goal', 'recurringRule.ruleWeekdays', 'recurringRule.ruleSlots.supplementDetail'];
    }

    private function reconcileStock(SupplementCourse $course, Request $request): void
    {
        $course->loadMissing('supplement');
        $supplement = $course->supplement;
        $supplement->setRelation('user', $request->user());
        $stock = $this->stock->forSupplement($supplement);
        $this->proposals->reconcile(
            $supplement,
            $this->forecasts->forecast($supplement, $stock),
        );
    }
}
