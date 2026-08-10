<?php

namespace Tests\Feature\CoreDailyLoop;

use Carbon\CarbonImmutable;

class DailyReviewApiTest extends CoreDailyLoopTestCase
{
    private const DATE = '2026-08-10';

    public function test_missing_review_is_returned_as_an_explicit_null(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $this->getJson('/api/daily-reviews/'.self::DATE)
            ->assertOk()
            ->assertExactJson(['data' => null]);
    }

    public function test_valid_review_is_saved_and_today_reports_it_complete(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 20:00:00 UTC');

        try {
            $owner = $this->createUser();
            $this->actingAs($owner);

            $response = $this->putJson('/api/daily-reviews/'.self::DATE, [
                'mood' => 8,
                'energy' => 7,
                'stress' => 3,
                'day_rating' => 9,
                'went_well' => 'Finished the important work.',
                'improve_tomorrow' => 'Start earlier.',
                'notes' => 'A complete reflection.',
            ])->assertOk()
                ->assertJsonPath('data.review_date', self::DATE)
                ->assertJsonPath('data.mood', 8)
                ->assertJsonPath('data.energy', 7)
                ->assertJsonPath('data.stress', 3)
                ->assertJsonPath('data.day_rating', 9)
                ->assertJsonPath('data.completed_at', '2026-08-10T20:00:00.000000Z')
                ->assertJsonStructure(['data' => [
                    'id', 'review_date', 'mood', 'energy', 'stress', 'day_rating',
                    'went_well', 'improve_tomorrow', 'notes', 'completed_at',
                ]]);

            $this->assertDatabaseHas('daily_reviews', [
                'id' => $response->json('data.id'),
                'user_id' => $owner->id,
                'review_date' => self::DATE,
            ]);

            $this->getJson('/api/today?date='.self::DATE)
                ->assertOk()
                ->assertJsonPath('review.id', $response->json('data.id'))
                ->assertJsonPath('review.review_date', self::DATE)
                ->assertJsonPath('review.completed_at', '2026-08-10T20:00:00.000000Z');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_upsert_updates_one_review_and_preserves_the_first_completion_instant(): void
    {
        CarbonImmutable::setTestNow('2026-08-10 20:00:00 UTC');

        try {
            $owner = $this->createUser();
            $this->actingAs($owner);

            $reviewId = $this->putJson('/api/daily-reviews/'.self::DATE, [
                'mood' => 6,
                'went_well' => 'First version',
            ])->assertOk()->json('data.id');

            CarbonImmutable::setTestNow('2026-08-10 21:30:00 UTC');

            $this->putJson('/api/daily-reviews/'.self::DATE, [
                'mood' => 9,
                'went_well' => 'Updated version',
            ])->assertOk()
                ->assertJsonPath('data.id', $reviewId)
                ->assertJsonPath('data.mood', 9)
                ->assertJsonPath('data.went_well', 'Updated version')
                ->assertJsonPath('data.completed_at', '2026-08-10T20:00:00.000000Z');

            $this->assertDatabaseCount('daily_reviews', 1);
            $this->assertDatabaseHas('daily_reviews', [
                'id' => $reviewId,
                'mood' => 9,
                'went_well' => 'Updated version',
                'completed_at' => '2026-08-10 20:00:00',
            ]);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_ratings_and_empty_payload_are_rejected_without_creating_a_review(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $this->putJson('/api/daily-reviews/'.self::DATE, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request');

        $this->putJson('/api/daily-reviews/'.self::DATE, [
            'mood' => 0,
            'energy' => 11,
            'stress' => -1,
            'day_rating' => 12,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['mood', 'energy', 'stress', 'day_rating']);

        $this->assertDatabaseCount('daily_reviews', 0);
    }

    public function test_reflection_text_limits_are_enforced_without_mutation(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $this->putJson('/api/daily-reviews/'.self::DATE, [
            'went_well' => str_repeat('w', 5001),
            'improve_tomorrow' => str_repeat('i', 5001),
            'notes' => str_repeat('n', 10001),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['went_well', 'improve_tomorrow', 'notes']);

        $this->assertDatabaseCount('daily_reviews', 0);
    }

    public function test_review_date_paths_are_strict_calendar_dates(): void
    {
        $owner = $this->createUser();
        $this->actingAs($owner);

        $this->getJson('/api/daily-reviews/not-a-date')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');

        $this->putJson('/api/daily-reviews/2026-02-30', ['mood' => 7])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');

        $this->assertDatabaseCount('daily_reviews', 0);
    }

    public function test_same_review_date_is_isolated_per_owner(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test', 'Other Owner');
        $ownerReview = $this->createReview($owner, self::DATE, ['notes' => 'Owner notes']);
        $otherReview = $this->createReview($other, self::DATE, ['notes' => 'Other notes']);

        $this->actingAs($owner);
        $this->getJson('/api/daily-reviews/'.self::DATE)
            ->assertOk()
            ->assertJsonPath('data.id', $ownerReview->id)
            ->assertJsonPath('data.notes', 'Owner notes')
            ->assertJsonMissing(['id' => $otherReview->id]);

        $this->putJson('/api/daily-reviews/'.self::DATE, ['notes' => 'Owner updated'])
            ->assertOk()
            ->assertJsonPath('data.id', $ownerReview->id);

        $this->assertDatabaseHas('daily_reviews', [
            'id' => $otherReview->id,
            'user_id' => $other->id,
            'notes' => 'Other notes',
        ]);
        $this->assertDatabaseCount('daily_reviews', 2);
    }
}
