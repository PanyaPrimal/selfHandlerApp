<?php

namespace App\Http\Controllers;

use App\Http\Requests\TrainingGoalMutationRequest;
use App\Http\Resources\TrainingGoalResource;
use App\Models\Goal;
use App\Models\TrainingGoalDetail;
use App\Services\TrainingGoalProgressService;
use App\Services\TrainingGoalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TrainingGoalController extends Controller
{
    public function __construct(
        private readonly TrainingGoalService $goals,
        private readonly TrainingGoalProgressService $progress,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), ['archived' => ['sometimes', 'boolean']]);
        $validator->after(function ($validator) use ($request): void {
            if (array_diff(array_keys($request->query()), ['archived']) !== []) {
                $validator->errors()->add('request', __('messages.unknown_fields'));
            }
        });
        $validator->validate();
        $goals = Goal::query()->ownedBy($request->user())
            ->where('type', Goal::TYPE_TRAINING)
            ->where('is_archived', $request->boolean('archived'))
            ->with(['trainingDetail.exercise', 'trainingDetail.program', 'user.profile'])
            ->orderBy('status')->orderBy('target_date')->orderBy('id')->get();
        $this->decorate($goals);

        return response()->json([
            'data' => TrainingGoalResource::collection($goals)->resolve($request),
            'kinds' => TrainingGoalDetail::KINDS,
        ]);
    }

    public function store(TrainingGoalMutationRequest $request): JsonResponse
    {
        $goal = $this->goals->create($request->user(), $request->validated());

        return $this->one($goal, $request, 201);
    }

    public function update(TrainingGoalMutationRequest $request, Goal $goal): JsonResponse
    {
        $goal = $this->goals->update($goal, $request->user(), $request->validated());

        return $this->one($goal, $request);
    }

    private function one(Goal $goal, Request $request, int $status = 200): JsonResponse
    {
        $goals = $goal->newCollection([$goal]);
        $this->decorate($goals);

        return response()->json(['data' => TrainingGoalResource::make($goal)->resolve($request)], $status);
    }

    private function decorate($goals): void
    {
        $descriptions = $this->progress->describeMany($goals);
        foreach ($goals as $goal) {
            $goal->setAttribute('training_progress_projection', $descriptions[$goal->id]);
        }
    }
}
