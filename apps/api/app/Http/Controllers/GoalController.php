<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use App\Models\Routine;
use App\Models\User;
use App\Services\Finance\FinanceGoalService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GoalController extends Controller
{
    public function __construct(private readonly FinanceGoalService $financeGoals) {}

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
        $finance = $this->financeGoals->list($user, $request->boolean('archived'))->keyBy('id');

        return response()->json(['data' => $goals->map(fn (Goal $goal) => $goal->type === Goal::TYPE_FINANCE
            ? $this->unifiedFinanceGoal($user, $goal, $finance->get($goal->id)) : $goal)->values()]);
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
                'request' => __('messages.goal_field_required'),
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

    private function freshFor(Goal $goal, User $user): Goal|array
    {
        $fresh = $goal->fresh()->load($this->relations($user));

        return $fresh->type === Goal::TYPE_FINANCE ? $this->unifiedFinanceGoal($user, $fresh) : $fresh;
    }

    /** @return array<string,mixed> */
    private function unifiedFinanceGoal(User $user, Goal $goal, ?array $projection = null): array
    {
        $projection ??= $this->financeGoals->one($user, $goal);

        return [
            'id' => $goal->id, 'name' => $goal->name, 'description' => $goal->description,
            'type' => Goal::TYPE_FINANCE, 'status' => $goal->status,
            'target_date' => $goal->target_date?->format('Y-m-d'),
            'completed_at' => $goal->completed_at?->toISOString(),
            'is_archived' => $goal->is_archived, 'archived_at' => $goal->archived_at?->toISOString(),
            'routines' => $goal->routines, 'finance' => $projection,
        ];
    }
}
