<?php

namespace Tests\Feature\Review;

use App\Models\DailyReview;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PeriodicReviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_workspace_canonicalizes_every_anchor_and_reopens_one_reflection(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $this->getJson('/api/periodic-reviews/weekly/2026-08-12')
            ->assertOk()
            ->assertJsonPath('data.period.type', 'weekly')
            ->assertJsonPath('data.period.anchor', '2026-08-12')
            ->assertJsonPath('data.period.start', '2026-08-10')
            ->assertJsonPath('data.period.end', '2026-08-16')
            ->assertJsonPath('data.review', null)
            ->assertJsonPath('data.well_being.reviewed_days', 0)
            ->assertJsonStructure(['data' => ['modules' => [
                'routines', 'sleep', 'workouts', 'nutrition', 'supplements', 'habits', 'planner', 'finance',
            ]]]);

        $saved = $this->putJson('/api/periodic-reviews/weekly/2026-08-12', [
            'period_rating' => 8,
            'worked_well' => '  Focused work  ',
            'did_not_work' => '',
            'next_focus' => 'Ship the next slice',
        ])->assertOk()
            ->assertJsonPath('data.period_type', 'weekly')
            ->assertJsonPath('data.period_start', '2026-08-10')
            ->assertJsonPath('data.period_end', '2026-08-16')
            ->assertJsonPath('data.worked_well', 'Focused work')
            ->assertJsonPath('data.did_not_work', null)
            ->json('data');

        $this->getJson('/api/periodic-reviews/weekly/2026-08-16')
            ->assertOk()
            ->assertJsonPath('data.period.start', '2026-08-10')
            ->assertJsonPath('data.review.id', $saved['id'])
            ->assertJsonPath('data.review.next_focus', 'Ship the next slice');

        $this->putJson('/api/periodic-reviews/weekly/2026-08-10', [
            'learned' => 'Canonical aliases are one review',
        ])->assertOk()
            ->assertJsonPath('data.id', $saved['id'])
            ->assertJsonPath('data.completed_at', $saved['completed_at'])
            ->assertJsonPath('data.worked_well', 'Focused work')
            ->assertJsonPath('data.next_focus', 'Ship the next slice')
            ->assertJsonPath('data.learned', 'Canonical aliases are one review');
        $this->assertDatabaseCount('periodic_reviews', 1);
    }

    public function test_monthly_upsert_preserves_first_completion_and_handles_leap_boundary(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        CarbonImmutable::setTestNow('2028-03-01 10:00:00 UTC');

        $first = $this->putJson('/api/periodic-reviews/monthly/2028-02-29', [
            'learned' => 'Consistency compounds',
        ])->assertOk()
            ->assertJsonPath('data.period_start', '2028-02-01')
            ->assertJsonPath('data.period_end', '2028-02-29')
            ->json('data.completed_at');

        CarbonImmutable::setTestNow('2028-03-02 10:00:00 UTC');
        $this->putJson('/api/periodic-reviews/monthly/2028-02-01', [
            'learned' => 'Edited lesson', 'notes' => 'Keep the evidence visible',
        ])->assertOk()
            ->assertJsonPath('data.completed_at', $first)
            ->assertJsonPath('data.learned', 'Edited lesson');

        $this->assertDatabaseCount('periodic_reviews', 1);
        CarbonImmutable::setTestNow();
    }

    public function test_payload_type_date_and_limits_are_strict_without_mutation(): void
    {
        $this->actingAs(User::factory()->create());

        $this->putJson('/api/periodic-reviews/weekly/2026-08-12', [])->assertUnprocessable();
        $this->putJson('/api/periodic-reviews/weekly/2026-08-12', ['notes' => '   '])->assertUnprocessable();
        $this->putJson('/api/periodic-reviews/weekly/2026-08-12', ['period_rating' => 11])->assertUnprocessable();
        $this->putJson('/api/periodic-reviews/weekly/2026-08-12', ['worked_well' => str_repeat('x', 5001)])
            ->assertUnprocessable();
        $this->putJson('/api/periodic-reviews/monthly/2026-02-30', ['notes' => 'invalid'])
            ->assertUnprocessable();
        $this->putJson('/api/periodic-reviews/yearly/2026-08-12', ['notes' => 'invalid'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('periodic_reviews', 0);
    }

    public function test_periodic_reviews_are_owner_isolated_and_authentication_is_required(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($owner)->putJson('/api/periodic-reviews/weekly/2026-08-12', ['notes' => 'Owner'])
            ->assertOk();
        $this->actingAs($other)->getJson('/api/periodic-reviews/weekly/2026-08-12')
            ->assertOk()->assertJsonPath('data.review', null);
        $this->actingAs($other)->putJson('/api/periodic-reviews/weekly/2026-08-12', ['notes' => 'Other'])
            ->assertOk();

        $this->assertDatabaseCount('periodic_reviews', 2);
        $this->assertDatabaseHas('periodic_reviews', ['user_id' => $owner->id, 'notes' => 'Owner']);
        $this->assertDatabaseHas('periodic_reviews', ['user_id' => $other->id, 'notes' => 'Other']);

    }

    public function test_periodic_review_requires_authentication(): void
    {
        $this->getJson('/api/periodic-reviews/weekly/2026-08-12')->assertUnauthorized();
        $this->putJson('/api/periodic-reviews/weekly/2026-08-12', ['notes' => 'Anonymous'])->assertUnauthorized();
    }

    public function test_live_well_being_corrections_recompute_without_changing_saved_reflection(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $reflection = $this->actingAs($owner)
            ->putJson('/api/periodic-reviews/weekly/2026-08-12', ['notes' => 'Keep this reflection'])
            ->assertOk()->json('data');

        $first = DailyReview::query()->create([
            'user_id' => $owner->id, 'review_date' => '2026-08-10', 'mood' => 4,
            'energy' => 6, 'completed_at' => now(),
        ]);
        DailyReview::query()->create([
            'user_id' => $owner->id, 'review_date' => '2026-08-11', 'mood' => 8,
            'energy' => 10, 'completed_at' => now(),
        ]);
        DailyReview::query()->create([
            'user_id' => $foreign->id, 'review_date' => '2026-08-11', 'mood' => 1,
            'energy' => 1, 'completed_at' => now(),
        ]);

        $this->getJson('/api/periodic-reviews/weekly/2026-08-12')
            ->assertOk()
            ->assertJsonPath('data.well_being.reviewed_days', 2)
            ->assertJsonPath('data.well_being.mood', 6)
            ->assertJsonPath('data.well_being.energy', 8)
            ->assertJsonPath('data.review.id', $reflection['id'])
            ->assertJsonPath('data.review.notes', 'Keep this reflection');

        $first->update(['mood' => 10]);

        $this->getJson('/api/periodic-reviews/weekly/2026-08-12')
            ->assertOk()
            ->assertJsonPath('data.well_being.mood', 9)
            ->assertJsonPath('data.review.id', $reflection['id'])
            ->assertJsonPath('data.review.notes', 'Keep this reflection');
    }
}
