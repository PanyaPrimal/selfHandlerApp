<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Project;
use App\Models\Tag;
use App\Services\ItemCompletionGuard;
use App\Services\StorageSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;

class ItemController extends Controller
{
    private const RELATIONS = ['tags', 'children.tags'];

    private const DEFAULT_LIMIT = 200;

    public function __construct(
        private readonly ItemCompletionGuard $completion,
        private readonly StorageSummary $summary,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(Item::STATUSES)],
            'type' => ['sometimes', Rule::in(Item::TYPES)],
            'project_id' => ['sometimes', 'nullable', 'integer'],
            'tag' => ['sometimes', 'string', 'max:64'],
            'parent_id' => ['sometimes', 'nullable', 'integer'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        $items = Item::query()
            ->ownedBy($user)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when(
                array_key_exists('project_id', $filters),
                fn ($query) => $filters['project_id'] === null
                    ? $query->whereNull('project_id')
                    : $query->where('project_id', $filters['project_id']),
            )
            ->when(
                array_key_exists('parent_id', $filters),
                fn ($query) => $filters['parent_id'] === null
                    ? $query->whereNull('parent_id')
                    : $query->where('parent_id', $filters['parent_id']),
            )
            ->when($filters['tag'] ?? null, fn ($query, $tag) => $query->whereHas(
                'tags',
                fn ($tagQuery) => $tagQuery->where('tags.name', $tag),
            ))
            ->with(self::RELATIONS)
            ->orderByDesc('id')
            // Always bounded: an inbox grows without limit otherwise.
            ->limit($filters['limit'] ?? self::DEFAULT_LIMIT)
            ->get();

        return response()->json([
            'data' => $items,
            'inbox_count' => $this->summary->inboxCount($user),
            'types' => Item::TYPES,
            'statuses' => Item::STATUSES,
            'priorities' => Item::PRIORITIES,
        ]);
    }

    /**
     * Capture. A title is the only thing required; everything else is triage.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->validated($request);
        $tags = $this->pullTags($data);

        $item = DB::transaction(function () use ($data, $tags, $user): Item {
            $item = new Item(['user_id' => $user->id, ...$data]);

            if (array_key_exists('status', $data)) {
                $item->applyStatus($data['status']);
            }

            $item->save();

            if ($tags !== null) {
                $this->syncTags($item, $tags);
            }

            return $item;
        });

        return response()->json(['data' => $item->fresh(self::RELATIONS)], 201);
    }

    public function update(Request $request, Item $item): JsonResponse
    {
        $user = $request->user();
        abort_unless($item->isOwnedBy($user), 404);

        $data = $this->validated($request, partial: true, item: $item);
        $tags = $this->pullTags($data);

        if (($data['status'] ?? null) === Item::STATUS_DONE) {
            $this->completion->assertCompletable($item);
        }

        DB::transaction(function () use ($data, $item, $tags): void {
            $status = $data['status'] ?? null;
            unset($data['status']);

            $item->fill($data);

            if ($status !== null) {
                $item->applyStatus($status);
            }

            $item->save();

            if ($tags !== null) {
                $this->syncTags($item, $tags);
            }
        });

        return response()->json(['data' => $item->fresh(self::RELATIONS)]);
    }

    public function destroy(Request $request, Item $item): Response
    {
        abort_unless($item->isOwnedBy($request->user()), 404);

        // Children survive as parentless items; deleting a container must not
        // delete the work inside it.
        $item->delete();

        return response()->noContent();
    }

    /**
     * Replace an item's tags with exactly this set, creating unknown names.
     *
     * @param  list<string>  $names
     */
    private function syncTags(Item $item, array $names): void
    {
        $ids = [];

        foreach ($names as $name) {
            $tag = Tag::query()->firstOrCreate(
                ['user_id' => $item->user_id, 'name' => $name],
            );

            $ids[$tag->id] = ['user_id' => $item->user_id];
        }

        $item->tags()->sync($ids);
        $item->unsetRelation('tags');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>|null null when the request said nothing about tags
     */
    private function pullTags(array &$data): ?array
    {
        if (! array_key_exists('tags', $data)) {
            return null;
        }

        $names = array_values(array_unique(array_filter(
            array_map(static fn ($name): string => trim((string) $name), $data['tags'] ?? []),
            static fn (string $name): bool => $name !== '',
        )));

        unset($data['tags']);

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial = false, ?Item $item = null): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $validator = validator($request->all(), [
            'title' => [$required, 'string', 'max:200'],
            'type' => ['sometimes', Rule::in(Item::TYPES)],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(Item::STATUSES)],
            'priority' => ['sometimes', 'nullable', Rule::in(Item::PRIORITIES)],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'project_id' => ['sometimes', 'nullable', 'integer'],
            'parent_id' => ['sometimes', 'nullable', 'integer'],
            'is_blocker' => ['sometimes', 'boolean'],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:64'],
        ]);

        $validator->after(function (LaravelValidator $validator) use ($item, $request): void {
            $this->validateTitle($validator, $request);
            $this->validateProject($validator, $request);
            $this->validateParent($validator, $request, $item);
        });

        $data = $validator->validate();

        if ($partial && $data === []) {
            throw ValidationException::withMessages([
                'request' => 'Provide at least one field to update.',
            ]);
        }

        if (array_key_exists('title', $data)) {
            $data['title'] = trim($data['title']);
        }

        return $data;
    }

    private function validateTitle(LaravelValidator $validator, Request $request): void
    {
        if ($request->has('title') && trim((string) $request->input('title')) === '') {
            $validator->errors()->add('title', 'Write something to capture.');
        }
    }

    private function validateProject(LaravelValidator $validator, Request $request): void
    {
        $projectId = $request->input('project_id');

        if (! $request->has('project_id') || $projectId === null) {
            return;
        }

        $owned = Project::query()
            ->ownedBy($request->user())
            ->whereKey($projectId)
            ->exists();

        if (! $owned) {
            $validator->errors()->add('project_id', 'That project does not exist.');
        }
    }

    private function validateParent(LaravelValidator $validator, Request $request, ?Item $item): void
    {
        $parentId = $request->input('parent_id');

        if (! $request->has('parent_id') || $parentId === null) {
            return;
        }

        if ($item && (int) $parentId === $item->id) {
            $validator->errors()->add('parent_id', 'An item cannot be its own parent.');

            return;
        }

        $parent = Item::query()->ownedBy($request->user())->whereKey($parentId)->first();

        if (! $parent) {
            $validator->errors()->add('parent_id', 'That item does not exist.');

            return;
        }

        // One level of nesting: a child cannot become a parent. This keeps the
        // blocking rule a single query instead of a walk down a tree.
        if ($parent->parent_id !== null) {
            $validator->errors()->add(
                'parent_id',
                'Items can be nested one level deep. Attach this to the item at the top instead.',
            );

            return;
        }

        if ($item && $item->children()->exists()) {
            $validator->errors()->add(
                'parent_id',
                'This item already has children, so it cannot become a child itself.',
            );
        }
    }
}
