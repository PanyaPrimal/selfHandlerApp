<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\StorageSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProjectController extends Controller
{
    public function __construct(private readonly StorageSummary $summary) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $projects = Project::query()
            ->ownedBy($user)
            ->orderBy('is_archived')
            ->orderBy('name')
            ->get();

        // One grouped query for every project, rather than one query each.
        $counts = $this->summary->projectCounts($user);

        return response()->json([
            'data' => $projects->map(fn (Project $project): array => [
                ...$project->toArray(),
                'open_count' => $counts[$project->id]['open'] ?? 0,
                'completed_count' => $counts[$project->id]['completed'] ?? 0,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validated($request, $user->id);

        $project = Project::create(['user_id' => $user->id, ...$data]);

        return response()->json(['data' => $project], 201);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        abort_unless($project->isOwnedBy($user), 404);

        $data = $this->validated($request, $user->id, partial: true, project: $project);

        if (array_key_exists('is_archived', $data) && $data['is_archived'] !== $project->is_archived) {
            $data['archived_at'] = $data['is_archived'] ? now() : null;
        }

        $project->update($data);

        return response()->json(['data' => $project->fresh()]);
    }

    public function destroy(Request $request, Project $project): Response
    {
        abort_unless($project->isOwnedBy($request->user()), 404);

        // Items keep existing without a project; the container goes, the work stays.
        $project->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(
        Request $request,
        int $userId,
        bool $partial = false,
        ?Project $project = null,
    ): array {
        $required = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [
                $required,
                'string',
                'max:160',
                Rule::unique('projects', 'name')
                    ->where(fn ($query) => $query->where('user_id', $userId))
                    ->ignore($project?->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_archived' => ['sometimes', 'boolean'],
        ]);

        if ($partial && $data === []) {
            throw ValidationException::withMessages([
                'request' => 'Provide at least one field to update.',
            ]);
        }

        return $data;
    }
}
