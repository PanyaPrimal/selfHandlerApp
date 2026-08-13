<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExerciseMutationRequest;
use App\Http\Resources\ExerciseResource;
use App\Models\Exercise;
use App\Services\ExerciseCatalogueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ExerciseController extends Controller
{
    public function __construct(private readonly ExerciseCatalogueService $catalogue) {}

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), ['state' => ['sometimes', Rule::in(['active', 'archived', 'all'])]]);
        $validator->after(function ($validator) use ($request): void {
            if (array_diff(array_keys($request->query()), ['state']) !== []) {
                $validator->errors()->add('request', __('messages.unknown_fields'));
            }
        });
        $data = $validator->validate();
        $exercises = $this->catalogue->visible($request->user(), $data['state'] ?? 'active');

        return response()->json([
            'data' => ExerciseResource::collection($exercises)->resolve($request),
            'options' => [
                'muscle_groups' => ['legs', 'chest', 'back', 'shoulders', 'arms', 'core', 'full_body', 'other'],
                'equipment' => ['barbell', 'dumbbell', 'machine', 'bodyweight', 'band', 'other'],
                'exercise_types' => [Exercise::TYPE_STRENGTH, Exercise::TYPE_MOBILITY],
            ],
        ]);
    }

    public function store(ExerciseMutationRequest $request): JsonResponse
    {
        $exercise = Exercise::create(['user_id' => $request->user()->id, ...$request->validated()]);

        return response()->json(['data' => ExerciseResource::make($exercise)->resolve($request)], 201);
    }

    public function update(ExerciseMutationRequest $request, Exercise $exercise): JsonResponse
    {
        $exercise = $this->catalogue->update($exercise, $request->user(), $request->validated());

        return response()->json(['data' => ExerciseResource::make($exercise)->resolve($request)]);
    }
}
