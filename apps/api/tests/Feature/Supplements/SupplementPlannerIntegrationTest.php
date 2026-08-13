<?php

namespace Tests\Feature\Supplements;

class SupplementPlannerIntegrationTest extends SupplementTestCase
{
    public function test_planner_projects_deep_link_actions_and_allows_same_day_different_slots(): void
    {
        $owner = $this->createUser();
        $course = $this->createCourse($owner, attributes: ['schedule' => [
            'frequency' => 'daily', 'interval_count' => 1, 'weekdays' => [], 'cycle' => null,
            'slots' => [
                ['slot' => 'morning', 'time' => '08:00', 'intake_context' => 'with_food'],
                ['slot' => 'evening', 'time' => '20:00', 'intake_context' => 'empty_stomach'],
            ],
        ]]);
        $morning = $this->occurrence($course, slot: 'morning');
        $evening = $this->occurrence($course, slot: 'evening');
        $this->occurrence($course, '2026-08-14', 'morning')->delete();

        $response = $this->actingAs($owner)->getJson('/api/planner/day?date='.self::TODAY)->assertOk();
        $this->assertSame(['supplement', 'supplement'], array_column($response->json('entries'), 'source'));
        $this->assertSame(['skip', 'reschedule'], $response->json('entries.0.actions'));
        $this->assertStringContainsString('/supplements?date='.self::TODAY, $response->json('entries.0.meta.action_url'));

        $this->actingAs($owner)->patchJson("/api/planner/occurrences/{$morning->id}/reschedule", [
            'rescheduled_to' => '2026-08-14',
        ])->assertOk();
        $this->actingAs($owner)->patchJson("/api/planner/occurrences/{$evening->id}/reschedule", [
            'rescheduled_to' => '2026-08-14',
        ])->assertUnprocessable();

        $this->actingAs($owner)->patchJson("/api/supplement-courses/{$course->id}", [
            'is_active' => false,
        ])->assertOk();
        $this->actingAs($owner)->getJson('/api/planner/day?date=2026-08-14')
            ->assertOk()->assertJsonMissing(['source' => 'supplement']);
        $this->actingAs($owner)->getJson('/api/supplements/days/2026-08-14')
            ->assertOk()->assertJsonCount(0, 'data.occurrences');
    }
}
