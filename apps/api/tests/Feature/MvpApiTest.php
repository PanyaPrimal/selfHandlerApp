<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\Routine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MvpApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_returns_scheduled_routines_and_summary(): void
    {
        $user = User::factory()->create();

        Routine::create([
            'user_id' => $user->id,
            'name' => 'Morning walk',
            'schedule_type' => 'daily',
        ]);

        Routine::create([
            'user_id' => $user->id,
            'name' => 'Wednesday-only routine',
            'schedule_type' => 'weekdays',
            'weekdays' => ['WE'],
        ]);

        $this->actingAs($user)
            ->getJson('/api/today?date=2026-06-22')
            ->assertOk()
            ->assertJsonPath('summary.scheduled', 1)
            ->assertJsonPath('summary.done', 0)
            ->assertJsonPath('summary.completion_rate', 0)
            ->assertJsonPath('routines.0.name', 'Morning walk');
    }

    public function test_routine_log_can_be_upserted(): void
    {
        $user = User::factory()->create();
        $routine = Routine::create([
            'user_id' => $user->id,
            'name' => 'Read',
        ]);

        $this->actingAs($user)
            ->putJson("/api/routines/{$routine->id}/logs/2026-06-22", [
                'status' => 'done',
                'note' => 'Twenty pages',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.note', 'Twenty pages');

        $this->assertDatabaseHas('routine_logs', [
            'user_id' => $user->id,
            'routine_id' => $routine->id,
            'log_date' => '2026-06-22 00:00:00',
            'status' => 'done',
        ]);

        $this->actingAs($user)
            ->putJson("/api/routines/{$routine->id}/logs/2026-06-22", [
                'status' => 'skipped',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'skipped');

        $this->assertDatabaseCount('routine_logs', 1);
    }

    public function test_daily_review_can_be_upserted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/daily-reviews/2026-06-22', [
                'mood' => 8,
                'energy' => 7,
                'stress' => 3,
                'day_rating' => 8,
                'went_well' => 'Kept the morning routine.',
                'improve_tomorrow' => 'Start earlier.',
            ])
            ->assertOk()
            ->assertJsonPath('data.mood', 8)
            ->assertJsonPath('data.went_well', 'Kept the morning routine.');

        $this->actingAs($user)
            ->putJson('/api/daily-reviews/2026-06-22', [
                'mood' => 6,
                'energy' => 5,
                'stress' => 4,
                'day_rating' => 7,
                'went_well' => 'Still showed up.',
            ])
            ->assertOk()
            ->assertJsonPath('data.mood', 6)
            ->assertJsonPath('data.went_well', 'Still showed up.');

        $this->assertDatabaseCount('daily_reviews', 1);
    }

    public function test_goal_can_be_linked_to_routine(): void
    {
        $user = User::factory()->create();
        $goal = Goal::create([
            'user_id' => $user->id,
            'name' => 'Improve discipline',
        ]);
        $routine = Routine::create([
            'user_id' => $user->id,
            'name' => 'Evening review',
        ]);

        $this->actingAs($user)
            ->postJson("/api/goals/{$goal->id}/routines/{$routine->id}")
            ->assertOk()
            ->assertJsonPath('data.routines.0.id', $routine->id);

        $this->assertDatabaseHas('goal_routine', [
            'user_id' => $user->id,
            'goal_id' => $goal->id,
            'routine_id' => $routine->id,
        ]);
    }
}
