<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use App\Models\Routine;
use App\Support\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GoalController extends Controller
{
    public function index(Request $request, CurrentUser $currentUser): JsonResponse
    {
        $user = $currentUser->resolve($request);

        $goals = Goal::query()
            ->where('user_id', $user->id)
            ->with('routines')
            ->orderBy('status')
            ->orderBy('target_date')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $goals]);
    }

    public function store(Request $request, CurrentUser $currentUser): JsonResponse
    {
        $user = $currentUser->resolve($request);
        $goal = Goal::create([...$this->validatedData($request), 'user_id' => $user->id]);

        return response()->json(['data' => $goal->load('routines')], 201);
    }

    public function update(Request $request, Goal $goal, CurrentUser $currentUser): JsonResponse
    {
        $user = $currentUser->resolve($request);
        abort_unless($goal->user_id === $user->id, 404);

        $goal->update($this->validatedData($request, partial: true));

        return response()->json(['data' => $goal->fresh('routines')]);
    }

    public function linkRoutine(Request $request, Goal $goal, Routine $routine, CurrentUser $currentUser): JsonResponse
    {
        $user = $currentUser->resolve($request);
        abort_unless($goal->user_id === $user->id && $routine->user_id === $user->id, 404);

        $goal->routines()->syncWithoutDetaching([
            $routine->id => ['user_id' => $user->id],
        ]);

        return response()->json(['data' => $goal->fresh('routines')]);
    }

    public function unlinkRoutine(Request $request, Goal $goal, Routine $routine, CurrentUser $currentUser): JsonResponse
    {
        $user = $currentUser->resolve($request);
        abort_unless($goal->user_id === $user->id && $routine->user_id === $user->id, 404);

        $goal->routines()->detach($routine->id);

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
            'type' => ['sometimes', 'string', 'max:50'],
            'status' => ['sometimes', Rule::in(['active', 'completed', 'abandoned'])],
            'target_date' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
        ]);
    }
}
