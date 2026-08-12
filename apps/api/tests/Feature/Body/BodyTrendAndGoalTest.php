<?php

namespace Tests\Feature\Body;

use App\Models\Goal;
use App\Models\GoalMilestone;
use App\Services\SafePaceValidator;
use App\ValueObjects\BodyMetric;

class BodyTrendAndGoalTest extends BodyTestCase
{
    /* ---------------------------------------------------------------- */
    /* Trend */
    /* ---------------------------------------------------------------- */

    public function test_the_trend_states_are_explicit(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $this->getJson('/api/body/trend?metric=body_mass')
            ->assertOk()
            ->assertJsonPath('state', 'empty')
            ->assertJsonPath('points', 0)
            ->assertJsonPath('change_per_week', null);

        $this->measure($owner, 'body_mass', '2026-08-01', 84000);

        // One point is "not enough information", which is not the same answer as
        // "no change", so the slope stays null rather than becoming zero.
        $this->getJson('/api/body/trend?metric=body_mass')
            ->assertOk()
            ->assertJsonPath('state', 'insufficient')
            ->assertJsonPath('points', 1)
            ->assertJsonPath('change_per_week', null);
    }

    public function test_the_slope_matches_a_hand_calculation_and_ignores_entry_order(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        // Exactly -500 g every 7 days, entered out of order on purpose.
        $this->measure($owner, 'body_mass', '2026-08-15', 83000);
        $this->measure($owner, 'body_mass', '2026-08-01', 84000);
        $this->measure($owner, 'body_mass', '2026-08-08', 83500);

        $this->getJson('/api/body/trend?metric=body_mass&from=2026-07-01&to=2026-08-31')
            ->assertOk()
            ->assertJsonPath('state', 'ready')
            ->assertJsonPath('points', 3)
            ->assertJsonPath('first.measured_on', '2026-08-01')
            ->assertJsonPath('last.measured_on', '2026-08-15')
            ->assertJsonPath('change_per_week', '-500.0000');
    }

    public function test_a_deleted_observation_leaves_the_trend(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $this->measure($owner, 'body_mass', '2026-08-01', 84000);
        $this->measure($owner, 'body_mass', '2026-08-08', 83500);
        $removed = $this->measure($owner, 'body_mass', '2026-08-15', 60000);

        $this->deleteJson("/api/body/measurements/{$removed->id}")->assertNoContent();

        $this->getJson('/api/body/trend?metric=body_mass&from=2026-07-01&to=2026-08-31')
            ->assertOk()
            ->assertJsonPath('points', 2)
            ->assertJsonPath('change_per_week', '-500.0000');
    }

    /* ---------------------------------------------------------------- */
    /* Body goal */
    /* ---------------------------------------------------------------- */

    public function test_a_body_goal_is_a_typed_detail_of_the_existing_goal(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $response = $this->postJson('/api/body/goals', [
            'name' => 'Reach 78 kg',
            'target_date' => '2027-06-01',
            'metric' => 'body_mass',
            'direction' => 'lose',
            'starting_value' => 84000,
            'target_value' => 78000,
            'milestones' => [
                ['target_value' => 80000],
                ['target_value' => 82000],
            ],
        ])->assertCreated();

        $goalId = $response->json('data.id');

        $this->assertSame(Goal::TYPE_BODY, Goal::query()->whereKey($goalId)->value('type'));
        $this->assertSame(1, Goal::query()->ownedBy($owner)->count());

        // Milestones are ordered along the direction of travel, not by raw value.
        $response
            ->assertJsonPath('data.body.current_value', null)
            ->assertJsonPath('data.body.progress', null)
            ->assertJsonPath('data.body.milestones.0.target_value', '82000.0000')
            ->assertJsonPath('data.body.milestones.1.target_value', '80000.0000')
            ->assertJsonPath('data.body.milestones.0.achieved', false)
            ->assertJsonPath('warnings', []);

        $this->measure($owner, 'body_mass', '2026-08-10', 81000);

        $this->getJson('/api/body/goals')
            ->assertOk()
            ->assertJsonPath('data.0.body.current_value', '81000.0000')
            ->assertJsonPath('data.0.body.progress', 0.5)
            ->assertJsonPath('data.0.body.milestones.0.achieved', true)
            ->assertJsonPath('data.0.body.milestones.1.achieved', false);
    }

    public function test_body_goals_stay_with_their_owner(): void
    {
        $owner = $this->createUser('owner@example.test');
        $other = $this->createUser('other@example.test');
        $this->actingAs($owner);

        $goalId = $this->postJson('/api/body/goals', [
            'name' => 'Owner goal',
            'metric' => 'body_mass',
            'direction' => 'lose',
            'starting_value' => 84000,
            'target_value' => 80000,
        ])->assertCreated()->json('data.id');

        $this->actingAs($other);

        $this->getJson('/api/body/goals')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/body/goals/{$goalId}", ['name' => 'Taken over'])->assertNotFound();
        $this->assertSame('Owner goal', Goal::query()->whereKey($goalId)->value('name'));
    }

    public function test_the_existing_goal_endpoint_is_unaffected(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $this->postJson('/api/goals', ['name' => 'A general goal'])->assertCreated();
        $this->postJson('/api/body/goals', [
            'name' => 'A body goal',
            'metric' => 'body_mass',
            'direction' => 'lose',
            'starting_value' => 84000,
            'target_value' => 80000,
        ])->assertCreated();

        // Both are goals, so both are listed; the body detail is served only by
        // the body endpoints, so no existing consumer has to change.
        $this->getJson('/api/goals')->assertOk()->assertJsonCount(2, 'data');
    }

    /* ---------------------------------------------------------------- */
    /* Safe pace */
    /* ---------------------------------------------------------------- */

    public function test_the_pace_boundary_is_exact_and_never_edits_the_target(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        // Exactly the CDC's stated upper bound of 2 lb a week over four weeks.
        $atBoundary = 4 * (float) SafePaceValidator::LOSS_LIMIT_GRAMS_PER_WEEK;

        $this->postJson('/api/body/goals', [
            'name' => 'At the boundary',
            'target_date' => '2026-09-09',
            'metric' => 'body_mass',
            'direction' => 'lose',
            'starting_value' => 90000,
            'target_value' => 90000 - $atBoundary,
        ])->assertCreated()->assertJsonPath('warnings', []);

        $response = $this->postJson('/api/body/goals', [
            'name' => 'Past the boundary',
            'target_date' => '2026-09-09',
            'metric' => 'body_mass',
            'direction' => 'lose',
            'starting_value' => 90000,
            'target_value' => 90000 - $atBoundary - 1000,
        ])->assertCreated();

        $response->assertJsonPath('warnings.0.code', 'pace_above_guidance');
        $this->assertStringContainsString('CDC', $response->json('warnings.0.message'));

        // The warning is advice, not a correction: the target is exactly what
        // the user asked for.
        $this->assertSame(
            number_format(90000 - $atBoundary - 1000, 4, '.', ''),
            $response->json('data.body.target_value'),
        );
    }

    public function test_no_boundary_is_invented_where_none_is_documented(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $this->assertFalse(BodyMetric::Waist->hasPaceBoundary());

        // An absurd waist target over one week still produces no warning,
        // because no authority and no product limitation covers it.
        $this->postJson('/api/body/goals', [
            'name' => 'Waist goal',
            'target_date' => '2026-08-19',
            'metric' => 'waist',
            'direction' => 'lose',
            'starting_value' => 1.00,
            'target_value' => 0.60,
        ])->assertCreated()->assertJsonPath('warnings', []);

        // Nor without a deadline, because no rate can be derived.
        $this->postJson('/api/body/goals', [
            'name' => 'Open-ended',
            'metric' => 'body_mass',
            'direction' => 'lose',
            'starting_value' => 90000,
            'target_value' => 60000,
        ])->assertCreated()->assertJsonPath('warnings', []);
    }

    public function test_the_gain_limit_is_presented_as_a_product_limitation(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $response = $this->postJson('/api/body/goals', [
            'name' => 'Fast gain',
            'target_date' => '2026-09-09',
            'metric' => 'body_mass',
            'direction' => 'gain',
            'starting_value' => 70000,
            'target_value' => 76000,
        ])->assertCreated();

        $message = $response->json('warnings.0.message');

        $this->assertStringContainsString("application's own limit", $message);
        $this->assertStringNotContainsString('CDC', $message);
    }

    public function test_milestones_are_replaced_as_a_set(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $goalId = $this->postJson('/api/body/goals', [
            'name' => 'Milestones',
            'metric' => 'body_mass',
            'direction' => 'lose',
            'starting_value' => 90000,
            'target_value' => 80000,
            'milestones' => [['target_value' => 88000], ['target_value' => 86000]],
        ])->assertCreated()->json('data.id');

        $this->assertSame(2, GoalMilestone::query()->where('goal_id', $goalId)->count());

        $this->patchJson("/api/body/goals/{$goalId}", [
            'milestones' => [['target_value' => 85000]],
        ])->assertOk()->assertJsonCount(1, 'data.body.milestones');

        $this->assertSame(1, GoalMilestone::query()->where('goal_id', $goalId)->count());
    }
}
