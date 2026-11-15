<?php

namespace Tests\Feature\Storage;

use App\Models\Item;
use App\Models\Project;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StorageApiTest extends StorageTestCase
{
    /* ---------------------------------------------------------------- */
    /* Capture */
    /* ---------------------------------------------------------------- */

    public function test_a_title_alone_is_enough_to_capture(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $this->postJson('/api/storage/items', ['title' => 'Book the dentist'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Book the dentist')
            ->assertJsonPath('data.status', Item::STATUS_INBOX)
            ->assertJsonPath('data.type', Item::TYPE_TASK)
            ->assertJsonPath('data.project_id', null)
            ->assertJsonPath('data.parent_id', null);

        $this->getJson('/api/storage/items?status=inbox')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('inbox_count', 1);
    }

    public function test_a_blank_title_is_refused_and_writes_nothing(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $this->postJson('/api/storage/items', ['title' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');

        $this->assertSame(0, Item::query()->count());
    }

    public function test_captures_are_listed_newest_first_and_titles_are_trimmed(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        foreach (['first', '  second  ', 'third'] as $title) {
            $this->postJson('/api/storage/items', ['title' => $title])->assertCreated();
        }

        $this->getJson('/api/storage/items')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'third')
            ->assertJsonPath('data.1.title', 'second')
            ->assertJsonPath('data.2.title', 'first');
    }

    /* ---------------------------------------------------------------- */
    /* Triage */
    /* ---------------------------------------------------------------- */

    public function test_triage_moves_an_item_out_of_the_inbox_and_keeps_its_identity(): void
    {
        $owner = $this->createUser();
        $project = $this->createProject($owner);
        $this->actingAs($owner);

        $id = $this->postJson('/api/storage/items', ['title' => 'Learn to weld'])
            ->assertCreated()->json('data.id');

        $this->patchJson("/api/storage/items/{$id}", [
            'type' => Item::TYPE_IDEA,
            'status' => Item::STATUS_ACTIVE,
            'project_id' => $project->id,
            'priority' => 'high',
            'tags' => ['workshop', 'someday'],
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.title', 'Learn to weld')
            ->assertJsonPath('data.type', Item::TYPE_IDEA)
            ->assertJsonPath('data.project_id', $project->id)
            ->assertJsonCount(2, 'data.tags');

        $this->getJson('/api/storage/items')->assertOk()->assertJsonPath('inbox_count', 0);
    }

    public function test_setting_tags_replaces_the_set_exactly(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);
        $item = $this->createItem($owner);

        $this->patchJson("/api/storage/items/{$item->id}", ['tags' => ['home', 'calls']])->assertOk();
        $this->assertSame(2, DB::table('item_tag')->count());

        $this->patchJson("/api/storage/items/{$item->id}", ['tags' => ['calls']])
            ->assertOk()
            ->assertJsonCount(1, 'data.tags')
            ->assertJsonPath('data.tags.0.name', 'calls');

        // The attachment goes; the tag itself survives for reuse.
        $this->assertSame(1, DB::table('item_tag')->count());
        $this->assertSame(2, Tag::query()->ownedBy($owner)->count());
    }

    public function test_lifecycle_timestamps_are_derived_by_the_server(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);
        $item = $this->createItem($owner);

        $this->patchJson("/api/storage/items/{$item->id}", [
            'status' => Item::STATUS_DONE,
            'completed_at' => '1999-01-01 00:00:00',
        ])->assertOk();

        $stored = $item->fresh();
        $this->assertNotNull($stored->completed_at);
        $this->assertNotSame('1999', $stored->completed_at->format('Y'));

        $this->patchJson("/api/storage/items/{$item->id}", ['status' => Item::STATUS_ACTIVE])->assertOk();
        $this->assertNull($item->fresh()->completed_at);
    }

    /* ---------------------------------------------------------------- */
    /* Hierarchy and blocking */
    /* ---------------------------------------------------------------- */

    public function test_an_open_blocking_child_refuses_the_parents_completion(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $parent = $this->createItem($owner, ['title' => 'Fit the shelf', 'type' => Item::TYPE_IDEA]);
        $blocker = $this->createItem($owner, [
            'title' => 'Buy brackets',
            'parent_id' => $parent->id,
            'is_blocker' => true,
        ]);
        $this->createItem($owner, ['title' => 'Pick a colour', 'parent_id' => $parent->id]);

        $this->patchJson("/api/storage/items/{$parent->id}", ['status' => Item::STATUS_DONE])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame(Item::STATUS_INBOX, $parent->fresh()->status);

        // A non-blocking child left open is not an obstacle; only the blocker is.
        $this->patchJson("/api/storage/items/{$blocker->id}", ['status' => Item::STATUS_DONE])->assertOk();
        $this->patchJson("/api/storage/items/{$parent->id}", ['status' => Item::STATUS_DONE])->assertOk();

        $this->assertSame(Item::STATUS_DONE, $parent->fresh()->status);
    }

    public function test_dropping_a_blocker_also_unblocks_the_parent(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $parent = $this->createItem($owner, ['title' => 'Parent']);
        $blocker = $this->createItem($owner, [
            'title' => 'Blocker',
            'parent_id' => $parent->id,
            'is_blocker' => true,
        ]);

        $this->patchJson("/api/storage/items/{$blocker->id}", ['status' => Item::STATUS_DROPPED])->assertOk();
        $this->patchJson("/api/storage/items/{$parent->id}", ['status' => Item::STATUS_DONE])->assertOk();
    }

    public function test_nesting_is_limited_to_three_levels_and_cycles_are_refused(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $parent = $this->createItem($owner, ['title' => 'Parent']);
        $child = $this->createItem($owner, ['title' => 'Child', 'parent_id' => $parent->id]);
        $grandchild = $this->createItem($owner, ['title' => 'Grandchild', 'parent_id' => $child->id]);
        $loose = $this->createItem($owner, ['title' => 'Loose']);

        // The third level is allowed.
        $this->patchJson("/api/storage/items/{$loose->id}", ['parent_id' => $child->id])->assertOk();
        $this->patchJson("/api/storage/items/{$loose->id}", ['parent_id' => null])->assertOk();

        // A fourth level is not.
        $this->patchJson("/api/storage/items/{$loose->id}", ['parent_id' => $grandchild->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        // A group cannot be nested so deep that its own children pass the limit.
        $this->patchJson("/api/storage/items/{$parent->id}", ['parent_id' => $loose->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        // Nothing can sit inside itself or inside its own descendant.
        $this->patchJson("/api/storage/items/{$loose->id}", ['parent_id' => $loose->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
        $this->patchJson("/api/storage/items/{$parent->id}", ['parent_id' => $grandchild->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->assertNull($loose->fresh()->parent_id);
        $this->assertNull($parent->fresh()->parent_id);
    }

    public function test_new_items_go_last_and_position_reorders_siblings(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);
        $project = $this->createProject($owner, 'Home');

        $ids = collect(['One', 'Two', 'Three'])->map(fn (string $title) => $this->postJson('/api/storage/items', [
            'title' => $title, 'project_id' => $project->id,
        ])->assertCreated()->json('data.id'))->all();
        $this->postJson('/api/storage/items', ['title' => 'Elsewhere'])->assertCreated();

        $order = fn () => Item::query()->where('project_id', $project->id)->whereNull('parent_id')
            ->orderBy('position')->orderBy('id')->pluck('title')->all();
        $this->assertSame(['One', 'Two', 'Three'], $order());

        $this->patchJson("/api/storage/items/{$ids[2]}", ['position' => 0])->assertOk()->assertJsonPath('data.position', 0);
        $this->assertSame(['Three', 'One', 'Two'], $order());

        // Inserting at an index shifts the following siblings.
        $this->postJson('/api/storage/items', ['title' => 'Between', 'project_id' => $project->id, 'position' => 1])->assertCreated();
        $this->assertSame(['Three', 'Between', 'One', 'Two'], $order());
    }

    public function test_children_join_their_parents_list_and_follow_it(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);
        $home = $this->createProject($owner, 'Home');
        $work = $this->createProject($owner, 'Work');

        $parentId = $this->postJson('/api/storage/items', ['title' => 'Parent', 'project_id' => $home->id])->json('data.id');
        $childId = $this->postJson('/api/storage/items', ['title' => 'Child', 'parent_id' => $parentId])
            ->assertCreated()->assertJsonPath('data.project_id', $home->id)->json('data.id');
        $grandchildId = $this->postJson('/api/storage/items', ['title' => 'Grandchild', 'parent_id' => $childId])
            ->assertCreated()->json('data.id');

        $this->patchJson("/api/storage/items/{$parentId}", ['project_id' => $work->id])->assertOk();

        $this->assertSame($work->id, Item::query()->find($childId)->project_id);
        $this->assertSame($work->id, Item::query()->find($grandchildId)->project_id);
    }

    public function test_a_cascading_check_closes_the_group_and_its_finished_parents(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $parent = $this->createItem($owner, ['title' => 'Trip']);
        $child = $this->createItem($owner, ['title' => 'Packing', 'parent_id' => $parent->id]);
        $blocker = $this->createItem($owner, ['title' => 'Socks', 'parent_id' => $child->id, 'is_blocker' => true]);
        $leaf = $this->createItem($owner, ['title' => 'Charger', 'parent_id' => $child->id]);
        $sibling = $this->createItem($owner, ['title' => 'Tickets', 'parent_id' => $parent->id]);

        // Checking a group closes everything below it, blockers included.
        $this->patchJson("/api/storage/items/{$child->id}", ['status' => Item::STATUS_DONE, 'cascade' => true])->assertOk();
        $this->assertSame(Item::STATUS_DONE, $blocker->fresh()->status);
        $this->assertSame(Item::STATUS_DONE, $leaf->fresh()->status);
        $this->assertNotNull($leaf->fresh()->completed_at);
        $this->assertSame(Item::STATUS_INBOX, $parent->fresh()->status, 'An open sibling keeps the parent open.');

        // The last open child closes its parent.
        $this->patchJson("/api/storage/items/{$sibling->id}", ['status' => Item::STATUS_DONE, 'cascade' => true])->assertOk();
        $this->assertSame(Item::STATUS_DONE, $parent->fresh()->status);

        // Unchecking a nested item reopens every closed parent above it.
        $this->patchJson("/api/storage/items/{$leaf->id}", ['status' => Item::STATUS_ACTIVE, 'cascade' => true])->assertOk();
        $this->assertSame(Item::STATUS_ACTIVE, $child->fresh()->status);
        $this->assertSame(Item::STATUS_ACTIVE, $parent->fresh()->status);
        $this->assertSame(Item::STATUS_DONE, $blocker->fresh()->status, 'Siblings keep their own state.');

        // Unchecking a group reopens the group below it.
        $this->patchJson("/api/storage/items/{$parent->id}", ['status' => Item::STATUS_DONE, 'cascade' => true])->assertOk();
        $this->patchJson("/api/storage/items/{$parent->id}", ['status' => Item::STATUS_ACTIVE, 'cascade' => true])->assertOk();
        foreach ([$child, $blocker, $leaf, $sibling] as $row) {
            $this->assertSame(Item::STATUS_ACTIVE, $row->fresh()->status);
            $this->assertNull($row->fresh()->completed_at);
        }
    }

    public function test_deleting_a_parent_keeps_its_children(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $parent = $this->createItem($owner, ['title' => 'Parent']);
        $child = $this->createItem($owner, ['title' => 'Child', 'parent_id' => $parent->id]);

        $this->deleteJson("/api/storage/items/{$parent->id}")->assertNoContent();

        $this->assertModelExists($child->fresh());
        $this->assertNull($child->fresh()->parent_id);
    }

    /* ---------------------------------------------------------------- */
    /* Projects */
    /* ---------------------------------------------------------------- */

    public function test_projects_are_unique_per_user_and_report_their_counts(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $projectId = $this->postJson('/api/storage/projects', ['name' => 'Renovation'])
            ->assertCreated()->json('data.id');

        $this->postJson('/api/storage/projects', ['name' => 'Renovation'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->createItem($owner, ['project_id' => $projectId, 'status' => Item::STATUS_ACTIVE]);
        $this->createItem($owner, ['project_id' => $projectId, 'status' => Item::STATUS_ACTIVE]);
        $this->createItem($owner, ['project_id' => $projectId, 'status' => Item::STATUS_DONE]);

        $this->getJson('/api/storage/projects')
            ->assertOk()
            ->assertJsonPath('data.0.open_count', 2)
            ->assertJsonPath('data.0.completed_count', 1);
    }

    public function test_deleting_a_project_keeps_its_items(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $project = $this->createProject($owner);
        $item = $this->createItem($owner, ['project_id' => $project->id]);

        $this->deleteJson("/api/storage/projects/{$project->id}")->assertNoContent();

        $this->assertModelExists($item->fresh());
        $this->assertNull($item->fresh()->project_id);
    }

    public function test_project_counts_use_one_query_regardless_of_project_count(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        for ($index = 0; $index < 20; $index++) {
            $project = $this->createProject($owner, "Project {$index}");
            $this->createItem($owner, ['project_id' => $project->id, 'status' => Item::STATUS_ACTIVE]);
        }

        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $this->getJson('/api/storage/projects')->assertOk();
            $queries = count($connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
        }

        // Session, projects and one grouped count. It must not grow per project.
        $this->assertLessThanOrEqual(6, $queries);
    }

    /* ---------------------------------------------------------------- */
    /* Ownership */
    /* ---------------------------------------------------------------- */

    public function test_storage_stays_inside_the_owning_account(): void
    {
        $owner = $this->createUser('owner@example.test');
        $other = $this->createUser('other@example.test');

        $ownerItem = $this->createItem($owner, ['title' => 'Owner item']);
        $ownerProject = $this->createProject($owner);
        $this->createItem($other, ['title' => 'Other item']);

        $this->actingAs($other);

        $this->getJson('/api/storage/items')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Other item');

        $this->patchJson("/api/storage/items/{$ownerItem->id}", ['title' => 'Taken'])->assertNotFound();
        $this->deleteJson("/api/storage/items/{$ownerItem->id}")->assertNotFound();
        $this->patchJson("/api/storage/projects/{$ownerProject->id}", ['name' => 'Taken'])->assertNotFound();

        // Another account's project or parent cannot be referenced either.
        $this->postJson('/api/storage/items', [
            'title' => 'Cross-account',
            'project_id' => $ownerProject->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('project_id');

        $this->postJson('/api/storage/items', [
            'title' => 'Cross-account',
            'parent_id' => $ownerItem->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $this->assertSame('Owner item', $ownerItem->fresh()->title);
    }

    public function test_the_model_refuses_a_relationship_that_crosses_accounts(): void
    {
        $owner = $this->createUser('owner@example.test');
        $other = $this->createUser('other@example.test');
        $otherProject = Project::create(['user_id' => $other->id, 'name' => 'Theirs']);

        $this->expectException(RuntimeException::class);

        Item::create([
            'user_id' => $owner->id,
            'title' => 'Wrong owner',
            'project_id' => $otherProject->id,
        ]);
    }
}
