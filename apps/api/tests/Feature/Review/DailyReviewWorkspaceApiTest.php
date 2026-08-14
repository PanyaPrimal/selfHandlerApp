<?php

namespace Tests\Feature\Review;

use App\Models\DailyReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyReviewWorkspaceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_workspace_is_one_owner_scoped_composition_with_explicit_empty_score(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        DailyReview::query()->create([
            'user_id' => $owner->id, 'review_date' => '2026-08-14', 'mood' => 8,
            'notes' => 'Owner reflection', 'completed_at' => now(),
        ]);
        DailyReview::query()->create([
            'user_id' => $foreign->id, 'review_date' => '2026-08-14', 'mood' => 1,
            'notes' => 'Foreign reflection', 'completed_at' => now(),
        ]);

        $this->actingAs($owner)->getJson('/api/review-workspaces/daily/2026-08-14')
            ->assertOk()
            ->assertJsonPath('data.period.type', 'daily')
            ->assertJsonPath('data.period.start', '2026-08-14')
            ->assertJsonPath('data.review.notes', 'Owner reflection')
            ->assertJsonPath('data.day_score.value', null)
            ->assertJsonPath('data.day_score.available_components', 0)
            ->assertJsonPath('data.day_score.total_components', 5)
            ->assertJsonPath('data.day_score.coverage_percentage', 0)
            ->assertJsonCount(5, 'data.day_score.components')
            ->assertJsonStructure(['data' => ['modules' => [
                'routines', 'routine_activities', 'sleep', 'workouts', 'nutrition', 'supplements',
                'habits', 'planner', 'finance',
            ]]]);
    }

    public function test_daily_workspace_rejects_invalid_date(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/review-workspaces/daily/2026-02-30')->assertUnprocessable();

    }

    public function test_daily_workspace_requires_authentication(): void
    {
        $this->getJson('/api/review-workspaces/daily/2026-08-14')->assertUnauthorized();
    }

    public function test_daily_workspace_preserves_legacy_nullable_review_fields(): void
    {
        $owner = User::factory()->create();
        DailyReview::query()->create([
            'user_id' => $owner->id, 'review_date' => '2026-08-14',
            'mood' => null, 'completed_at' => null,
        ]);

        $this->actingAs($owner)->getJson('/api/review-workspaces/daily/2026-08-14')
            ->assertOk()
            ->assertJsonPath('data.review.mood', null)
            ->assertJsonPath('data.review.completed_at', null);
    }

    public function test_today_keeps_legacy_module_keys_and_adds_score_and_new_sources(): void
    {
        $owner = User::factory()->create();
        DailyReview::query()->create([
            'user_id' => $owner->id, 'review_date' => '2026-08-14', 'mood' => 8,
            'completed_at' => now(),
        ]);

        $this->actingAs($owner)
            ->getJson('/api/today?date=2026-08-14')
            ->assertOk()
            ->assertJsonPath('review.user_id', $owner->id)
            ->assertJsonStructure(['review' => ['created_at', 'updated_at']])
            ->assertJsonStructure([
                'review', 'module_summaries' => [
                    'sleep', 'routine_activities', 'workouts', 'nutrition', 'supplements',
                    'routines', 'habits', 'planner', 'finance',
                ],
                'day_score' => ['value', 'available_components', 'total_components', 'components'],
            ]);
    }
}
