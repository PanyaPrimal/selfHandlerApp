<?php

namespace Tests\Feature\Supplements;

use App\Models\RecurringRule;
use App\Models\Supplement;
use App\Models\SupplementStockMovement;

class SupplementCourseApiTest extends SupplementTestCase
{
    public function test_user_creates_updates_pauses_and_restores_bounded_multi_slot_course(): void
    {
        $owner = $this->createUser();
        $supplement = $this->supplement($owner->id);
        SupplementStockMovement::create([
            'user_id' => $owner->id,
            'supplement_id' => $supplement->id,
            'kind' => 'restock',
            'quantity_delta' => '3.000000',
            'effective_on' => self::TODAY,
            'reason' => null,
            'note' => null,
        ]);
        $payload = [
            'supplement_id' => $supplement->id, 'goal_id' => null, 'name' => 'Morning and evening',
            'dose_quantity' => '1', 'dose_display_unit' => 'piece',
            'starts_on' => self::TODAY, 'duration_days' => 10, 'is_active' => true,
            'schedule' => [
                'frequency' => 'daily', 'interval_count' => 1, 'weekdays' => [], 'cycle' => null,
                'slots' => [
                    ['slot' => 'morning', 'time' => '08:00', 'intake_context' => 'with_food'],
                    ['slot' => 'evening', 'time' => '20:00', 'intake_context' => 'unspecified'],
                ],
            ],
        ];

        $course = $this->actingAs($owner)->postJson('/api/supplement-courses', $payload)
            ->assertCreated()
            ->assertJsonPath('data.ends_on', '2026-08-22')
            ->assertJsonPath('data.schedule.slots.1.slot', 'evening')
            ->assertJsonPath('data.schedule.timezone', 'UTC')
            ->json('data');

        $this->assertDatabaseCount('planned_occurrences', 20);
        $this->assertDatabaseHas('recurring_rules', [
            'owner_type' => RecurringRule::OWNER_SUPPLEMENT_COURSE,
            'owner_id' => $course['id'], 'interval_count' => 1,
        ]);
        $this->assertDatabaseHas('supplement_restock_proposals', [
            'supplement_id' => $supplement->id,
            'status' => 'open',
        ]);

        $this->actingAs($owner)->patchJson('/api/supplement-courses/'.$course['id'], [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseCount('planned_occurrences', 0);
        $this->assertDatabaseHas('supplement_restock_proposals', [
            'supplement_id' => $supplement->id,
            'status' => 'resolved',
        ]);

        $this->actingAs($owner)->patchJson('/api/supplement-courses/'.$course['id'], [
            'is_active' => true,
            'schedule' => [
                'frequency' => 'weekly', 'interval_count' => 2,
                'weekdays' => ['MO', 'TH'], 'cycle' => ['on_days' => 7, 'off_days' => 7],
                'slots' => [['slot' => 'morning', 'time' => '09:00', 'intake_context' => 'empty_stomach']],
            ],
        ])->assertOk()->assertJsonPath('data.schedule.interval_count', 2)
            ->assertJsonPath('data.schedule.cycle.on_days', 7);

        $this->actingAs($owner)->getJson('/api/supplement-courses')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_course_rejects_past_archived_foreign_and_closed_scope(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $archived = $this->supplement($owner->id, ['is_archived' => true, 'archived_at' => now()]);
        $foreign = $this->supplement($other->id);
        $base = [
            'goal_id' => null, 'name' => null, 'dose_quantity' => '1', 'dose_display_unit' => 'piece',
            'starts_on' => '2026-08-12', 'ends_on' => '2026-08-20', 'is_active' => true,
            'schedule' => [
                'frequency' => 'daily', 'interval_count' => 1, 'weekdays' => [], 'cycle' => null,
                'slots' => [['slot' => 'once', 'time' => '08:00', 'intake_context' => 'unspecified']],
            ],
        ];

        $active = $this->supplement($owner->id, ['name' => 'Active']);
        $this->actingAs($owner)->postJson('/api/supplement-courses', [
            ...$base, 'supplement_id' => $active->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['starts_on']);
        $this->actingAs($owner)->postJson('/api/supplement-courses', [
            ...$base, 'starts_on' => self::TODAY, 'supplement_id' => $archived->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['supplement_id']);
        $this->actingAs($owner)->postJson('/api/supplement-courses', [
            ...$base, 'starts_on' => self::TODAY, 'supplement_id' => $foreign->id,
        ])->assertNotFound();
        $this->actingAs($owner)->postJson('/api/supplement-courses', [
            ...$base, 'starts_on' => self::TODAY, 'supplement_id' => $archived->id, 'unknown' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['unknown']);
    }

    /** @param array<string, mixed> $attributes */
    private function supplement(int $userId, array $attributes = []): Supplement
    {
        return Supplement::create([
            'user_id' => $userId, 'name' => 'Capsules', 'category' => 'vitamin', 'form' => 'capsule',
            'stock_unit' => 'piece', 'preferred_display_unit' => 'piece',
            'usual_dose_quantity' => '1', ...$attributes,
        ]);
    }
}
