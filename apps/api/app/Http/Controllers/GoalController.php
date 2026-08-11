<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use App\Models\Routine;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GoalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $goals = Goal::query()
            ->ownedBy($user)
            ->where('is_archived', $request->boolean('archived'))
            ->with($this->relations($user))
            ->orderBy('status')
            ->orderBy('target_date')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $goals]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validatedData($request);

        $goal = DB::transaction(function () use ($data, $user): Goal {
            $goal = new Goal(['user_id' => $user->id]);
            $goal->applyLifecycle($data);
            $goal->save();

            return $goal;
        });

        return response()->json(['data' => $this->freshFor($goal, $user)], 201);
    }

    public function update(Request $request, Goal $goal): JsonResponse
    {
        $user = $request->user();
        abort_unless($goal->isOwnedBy($user), 404);

        $data = $this->validatedData($request, partial: true);

        DB::transaction(function () use ($data, $goal): void {
            $goal->applyLifecycle($data);
            $goal->save();
        });

        return response()->json(['data' => $this->freshFor($goal, $user)]);
    }

    public function linkRoutine(Request $request, Goal $goal, Routine $routine): JsonResponse
    {
        $user = $request->user();
        abort_unless($goal->isOwnedBy($user) && $routine->isOwnedBy($user), 404);

        $goal->routines()->syncWithoutDetaching([
            $routine->id => ['user_id' => $user->id],
        ]);

        return response()->json(['data' => $this->freshFor($goal, $user)]);
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
        $data = $request->validate([
            'name' => [$required, 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(['active', 'completed', 'abandoned'])],
            'target_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'is_archived' => ['sometimes', 'boolean'],
            'type' => ['prohibited'],
            'completed_at' => ['prohibited'],
            'archived_at' => ['prohibited'],
        ]);

        if ($partial && $data === []) {
            throw ValidationException::withMessages([
                'request' => 'Provide at least one goal field to update.',
            ]);
        }

        return $data;
    }

    /**
     * @return array<string, Closure>
     */
    private function relations(User $user): array
    {
        return [
            'routines' => fn ($query) => $query
                ->ownedBy($user)
                ->with('recurringRule.ruleWeekdays')
                ->orderBy('routines.sort_order')
                ->orderBy('routines.name')
                ->orderBy('routines.id'),
        ];
    }

    private function freshFor(Goal $goal, User $user): Goal
    {
        return $goal->fresh()->load($this->relations($user));
    }
}
