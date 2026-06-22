<?php

namespace App\Http\Controllers;

use App\Models\Routine;
use App\Support\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoutineController extends Controller
{
    public function index(Request $request, CurrentUser $currentUser): JsonResponse
    {
        $user = $currentUser->resolve($request);

        $routines = Routine::query()
            ->where('user_id', $user->id)
            ->with('goals')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $routines]);
    }

    public function store(Request $request, CurrentUser $currentUser): JsonResponse
    {
        $user = $currentUser->resolve($request);
        $data = $this->validatedData($request);

        $routine = Routine::create([...$data, 'user_id' => $user->id]);

        return response()->json(['data' => $routine->load('goals')], 201);
    }

    public function update(Request $request, Routine $routine, CurrentUser $currentUser): JsonResponse
    {
        $user = $currentUser->resolve($request);
        abort_unless($routine->user_id === $user->id, 404);

        $routine->update($this->validatedData($request, partial: true));

        return response()->json(['data' => $routine->fresh('goals')]);
    }

    public function destroy(Request $request, Routine $routine, CurrentUser $currentUser): JsonResponse
    {
        $user = $currentUser->resolve($request);
        abort_unless($routine->user_id === $user->id, 404);

        $routine->update(['is_active' => false]);

        return response()->json(status: 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'kind' => ['sometimes', Rule::in(['routine', 'sleep', 'habit'])],
            'schedule_type' => ['sometimes', Rule::in(['daily', 'weekdays'])],
            'weekdays' => ['nullable', 'array'],
            'weekdays.*' => [Rule::in(['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'])],
            'preferred_time' => ['nullable', 'date_format:H:i'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);
    }
}
