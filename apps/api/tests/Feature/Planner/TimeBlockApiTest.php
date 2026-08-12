<?php

namespace Tests\Feature\Planner;

use App\Models\TimeBlock;

class TimeBlockApiTest extends PlannerTestCase
{
    public function test_a_block_is_created_edited_and_deleted(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $id = $this->postJson('/api/planner/time-blocks', [
            'title' => '  Dentist  ',
            'block_date' => self::TODAY,
            'starts_at' => '14:00',
            'ends_at' => '15:00',
        ])->assertCreated()->assertJsonPath('data.title', 'Dentist')->json('data.id');

        $this->patchJson("/api/planner/time-blocks/{$id}", ['title' => 'Dentist, second visit'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Dentist, second visit');

        $this->deleteJson("/api/planner/time-blocks/{$id}")->assertNoContent();
        $this->assertSame(0, TimeBlock::query()->count());
    }

    public function test_times_are_optional_but_a_span_must_run_forwards(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        // "Dentist, Tuesday" is a real entry before the time is known.
        $this->postJson('/api/planner/time-blocks', [
            'title' => 'Dentist',
            'block_date' => self::TODAY,
        ])->assertCreated()->assertJsonPath('data.starts_at', null);

        $this->postJson('/api/planner/time-blocks', [
            'title' => 'Backwards',
            'block_date' => self::TODAY,
            'starts_at' => '15:00',
            'ends_at' => '14:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('ends_at');

        $this->postJson('/api/planner/time-blocks', [
            'title' => '   ',
            'block_date' => self::TODAY,
        ])->assertUnprocessable()->assertJsonValidationErrors('title');

        $this->assertSame(1, TimeBlock::query()->count());
    }

    public function test_overlapping_blocks_are_allowed(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        foreach (['First', 'Second'] as $title) {
            $this->postJson('/api/planner/time-blocks', [
                'title' => $title,
                'block_date' => self::TODAY,
                'starts_at' => '14:00',
                'ends_at' => '15:00',
            ])->assertCreated();
        }

        // Noting a conflict is normal use; the planner does not argue with the
        // user about their own day.
        $this->assertSame(2, TimeBlock::query()->count());
        $this->getJson('/api/planner/day')->assertOk()->assertJsonCount(2, 'entries');
    }

    public function test_blocks_stay_with_their_owner(): void
    {
        $owner = $this->createUser('owner@example.test');
        $other = $this->createUser('other@example.test');
        $block = $this->createBlock($owner, ['title' => 'Owner block']);

        $this->actingAs($other);

        $this->patchJson("/api/planner/time-blocks/{$block->id}", ['title' => 'Taken'])->assertNotFound();
        $this->deleteJson("/api/planner/time-blocks/{$block->id}")->assertNotFound();

        $this->assertSame('Owner block', $block->fresh()->title);
    }
}
