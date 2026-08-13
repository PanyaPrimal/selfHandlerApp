<?php

namespace Tests\Feature\Supplements;

class SupplementDailyLoopIntegrationTest extends SupplementTestCase
{
    public function test_supplements_planner_today_and_range_share_one_adherence_truth(): void
    {
        $owner = $this->createUser();
        $course = $this->createCourse($owner, attributes: [
            'schedule' => [
                'frequency' => 'daily', 'interval_count' => 1, 'weekdays' => [], 'cycle' => null,
                'slots' => [
                    ['slot' => 'morning', 'time' => '08:00', 'intake_context' => 'with_food'],
                    ['slot' => 'evening', 'time' => '20:00', 'intake_context' => 'unspecified'],
                ],
            ],
        ]);
        $morning = $this->occurrence($course, slot: 'morning');

        $this->actingAs($owner)->getJson('/api/supplements/days/'.self::TODAY)
            ->assertOk()->assertJsonCount(2, 'data.occurrences')
            ->assertJsonPath('data.summary.overdue', 1)
            ->assertJsonPath('data.summary.pending', 1)
            ->assertJsonPath('data.summary.adherence_percentage', 0);
        $this->actingAs($owner)->getJson('/api/planner/day?date='.self::TODAY)
            ->assertOk()->assertJsonFragment(['source' => 'supplement'])
            ->assertJsonPath('sources.4', 'supplement');

        $this->actingAs($owner)->putJson("/api/supplement-occurrences/{$morning->id}/intake", [
            'outcome' => 'taken', 'dose_quantity' => null, 'dose_display_unit' => null,
            'taken_time' => '08:30', 'note' => null,
        ])->assertCreated();

        $this->actingAs($owner)->getJson('/api/today?date='.self::TODAY)
            ->assertOk()
            ->assertJsonPath('module_summaries.supplements.done', 1)
            ->assertJsonPath('module_summaries.supplements.pending', 1)
            ->assertJsonPath('module_summaries.supplements.adherence_percentage', 100);
        $this->actingAs($owner)->getJson('/api/supplements/adherence?from=2026-08-13&to=2026-08-15')
            ->assertOk()
            ->assertJsonPath('data.summary.done', 1)
            ->assertJsonPath('data.summary.eligible', 1)
            ->assertJsonPath('data.days.0.adherence_percentage', 100)
            ->assertJsonCount(3, 'data.days');

        $this->actingAs($owner)->patchJson("/api/supplement-courses/{$course->id}", [
            'is_active' => false,
            'is_archived' => true,
        ])->assertOk();
        $this->actingAs($owner)->getJson('/api/supplements/days/'.self::TODAY)
            ->assertOk()->assertJsonCount(1, 'data.occurrences')
            ->assertJsonPath('data.summary.done', 1)
            ->assertJsonPath('data.summary.pending', 0);
    }

    public function test_planner_skip_delegates_to_supplement_fact_and_range_is_bounded(): void
    {
        $owner = $this->createUser();
        $course = $this->createCourse($owner);
        $occurrence = $this->occurrence($course);

        $this->actingAs($owner)->putJson("/api/planner/occurrences/{$occurrence->id}/skip")
            ->assertOk()->assertJsonPath('data.status', 'skipped');
        $this->assertDatabaseHas('supplement_intakes', [
            'supplement_course_id' => $course->id, 'outcome' => 'skipped',
        ]);
        $this->actingAs($owner)->getJson('/api/supplements/days/'.self::TODAY)
            ->assertOk()->assertJsonPath('data.summary.skipped', 1)
            ->assertJsonPath('data.summary.adherence_percentage', 0);
        $this->actingAs($owner)->getJson('/api/supplements/adherence?from=2026-01-01&to=2027-01-02')
            ->assertUnprocessable();
    }
}
