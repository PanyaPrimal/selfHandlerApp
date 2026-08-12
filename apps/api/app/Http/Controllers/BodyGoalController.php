<?php

namespace App\Http\Controllers;

use App\Models\BodyGoalDetail;
use App\Models\Goal;
use App\Models\GoalMilestone;
use App\Services\BodyGoalProgressService;
use App\Services\SafePaceValidator;
use App\ValueObjects\BodyMetric;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Body goals are ordinary goals with a typed detail.
 *
 * They live in the same `goals` table and keep the same lifecycle, so
 * `GET /api/goals` is untouched. These endpoints only add the body-specific
 * detail, its progress, and the pace warning.
 */
class BodyGoalController extends Controller
{
    private const RELATIONS = ['bodyDetail', 'milestones'];

    public function __construct(
        private readonly BodyGoalProgressService $progress,
        private readonly SafePaceValidator $pace,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $goals = Goal::query()
            ->ownedBy($request->user())
            ->where('type', Goal::TYPE_BODY)
            ->where('is_archived', $request->boolean('archived'))
            ->with(self::RELATIONS)
            ->orderBy('status')
            ->orderBy('target_date')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $goals->map(fn (Goal $goal): array => $this->present($goal)),
            'metrics' => BodyMetric::catalogue(),
            'directions' => BodyGoalDetail::DIRECTIONS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validated($request);

        $goal = DB::transaction(function () use ($data, $user): Goal {
            $goal = Goal::create([
                'user_id' => $user->id,
                'type' => Goal::TYPE_BODY,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'target_date' => $data['target_date'] ?? null,
            ]);

            $this->writeDetail($goal, $user->id, $data);

            return $goal;
        });

        return response()->json([
            'data' => $this->present($goal->fresh(self::RELATIONS)),
            'warnings' => $this->warnings($goal->fresh(self::RELATIONS), $user->calendarTimezone()),
        ], 201);
    }

    public function update(Request $request, Goal $goal): JsonResponse
    {
        $user = $request->user();
        abort_unless($goal->isOwnedBy($user) && $goal->type === Goal::TYPE_BODY, 404);

        $data = $this->validated($request, partial: true);

        DB::transaction(function () use ($data, $goal, $user): void {
            $goal->applyLifecycle(array_intersect_key($data, array_flip([
                'name', 'description', 'target_date', 'status', 'is_archived',
            ])));
            $goal->save();

            $this->writeDetail($goal, $user->id, $data, partial: true);
        });

        $fresh = $goal->fresh(self::RELATIONS);

        return response()->json([
            'data' => $this->present($fresh),
            'warnings' => $this->warnings($fresh, $user->calendarTimezone()),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeDetail(Goal $goal, int $userId, array $data, bool $partial = false): void
    {
        $detailFields = array_intersect_key($data, array_flip([
            'metric', 'direction', 'starting_value', 'target_value',
        ]));

        if ($detailFields !== [] || ! $partial) {
            BodyGoalDetail::query()->updateOrCreate(
                ['goal_id' => $goal->id],
                ['user_id' => $userId, ...$detailFields],
            );
        }

        if (! array_key_exists('milestones', $data)) {
            return;
        }

        // Milestones are replaced as a set: they describe one plan, and merging
        // them piecemeal would leave checkpoints from a plan the user dropped.
        GoalMilestone::query()->where('goal_id', $goal->id)->delete();

        foreach ($data['milestones'] ?? [] as $milestone) {
            GoalMilestone::create([
                'user_id' => $userId,
                'goal_id' => $goal->id,
                'target_value' => $milestone['target_value'],
                'target_date' => $milestone['target_date'] ?? null,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Goal $goal): array
    {
        return [
            'id' => $goal->id,
            'name' => $goal->name,
            'description' => $goal->description,
            'type' => $goal->type,
            'status' => $goal->status,
            'target_date' => $goal->target_date?->format('Y-m-d'),
            'completed_at' => $goal->completed_at,
            'is_archived' => $goal->is_archived,
            'archived_at' => $goal->archived_at,
            'body' => $this->progress->describe($goal),
        ];
    }

    /**
     * @return list<array{field: string, code: string, message: string}>
     */
    private function warnings(Goal $goal, string $timezone): array
    {
        $detail = $goal->bodyDetail;

        if (! $detail) {
            return [];
        }

        return $this->pace->warningsFor(
            $detail->metric,
            $detail->direction,
            (string) $detail->starting_value,
            (string) $detail->target_value,
            $goal->target_date?->format('Y-m-d'),
            CarbonImmutable::now($timezone)->toDateString(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'target_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::in(['active', 'completed', 'abandoned'])],
            'is_archived' => ['sometimes', 'boolean'],
            'metric' => [$required, Rule::in(BodyMetric::values())],
            'direction' => [$required, Rule::in(BodyGoalDetail::DIRECTIONS)],
            'starting_value' => [$required, 'numeric'],
            'target_value' => [$required, 'numeric'],
            'milestones' => ['sometimes', 'array', 'max:20'],
            'milestones.*.target_value' => ['required', 'numeric'],
            'milestones.*.target_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
    }
}
