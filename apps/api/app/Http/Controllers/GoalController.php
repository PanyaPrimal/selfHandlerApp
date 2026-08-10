<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use App\Models\Routine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GoalController extends Controller
{
    private const RELATIONS = ['routines', 'routines.scheduleWeekdays'];

    public function index(Request $request): JsonResponse
    {
        $goals = Goal::query()
            ->ownedBy($request->user())
            ->with(self::RELATIONS)
            ->orderBy('status')
            ->orderBy('target_date')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $goals]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $goal = Goal::create([...$this->validatedData($request), 'user_id' => $user->id]);

        return response()->json(['data' => $goal->load(self::RELATIONS)], 201);
    }

    public function update(Request $request, Goal $goal): JsonResponse
    {
        abort_unless($goal->isOwnedBy($request->user()), 404);

        $goal->update($this->validatedData($request, partial: true));

        return response()->json(['data' => $goal->fresh(self::RELATIONS)]);
    }

    public function linkRoutine(Request $request, Goal $goal, Routine $routine): JsonResponse
    {
        $user = $request->user();
        abort_unless($goal->isOwnedBy($user) && $routine->isOwnedBy($user), 404);

        $goal->routines()->syncWithoutDetaching([
            $routine->id => ['user_id' => $user->id],
        ]);

        return response()->json(['data' => $goal->fresh(self::RELATIONS)]);
    }

    public function unlinkRoutine(Request $request, Goal $goal, Routine $routine): JsonResponse
    {
        $user = $request->user();
        abort_unless($goal->isOwnedBy($user) && $routine->isOwnedBy($user), 404);

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
