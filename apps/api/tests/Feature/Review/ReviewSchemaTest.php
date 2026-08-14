<?php

namespace Tests\Feature\Review;

use App\Models\PeriodicReview;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ReviewSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_periodic_review_table_is_additive_owner_scoped_and_mysql_safe(): void
    {
        $this->assertTrue(Schema::hasColumns('periodic_reviews', [
            'id', 'user_id', 'period_type', 'period_start', 'period_end', 'period_rating',
            'worked_well', 'did_not_work', 'learned', 'next_focus', 'notes', 'completed_at',
            'created_at', 'updated_at',
        ]));

        foreach (Schema::getIndexes('periodic_reviews') as $index) {
            $this->assertLessThanOrEqual(64, strlen($index['name']), $index['name']);
        }
    }

    public function test_owner_type_and_canonical_start_are_unique(): void
    {
        $owner = User::factory()->create();
        PeriodicReview::query()->create($this->attributes($owner));

        try {
            PeriodicReview::query()->create($this->attributes($owner, ['notes' => 'duplicate']));
            $this->fail('A duplicate canonical periodic review was accepted.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        PeriodicReview::query()->create($this->attributes(User::factory()->create()));
        $this->assertDatabaseCount('periodic_reviews', 2);
    }

    public function test_model_rejects_noncanonical_boundaries(): void
    {
        $this->expectException(RuntimeException::class);

        PeriodicReview::query()->create($this->attributes(User::factory()->create(), [
            'period_end' => '2026-08-17',
        ]));
    }

    public function test_user_hard_delete_cascades_periodic_reviews(): void
    {
        $owner = User::factory()->create();
        $review = PeriodicReview::query()->create($this->attributes($owner));

        \DB::table('users')->where('id', $owner->id)->delete();

        $this->assertDatabaseMissing('periodic_reviews', ['id' => $review->id]);
    }

    public function test_feature_migration_is_reversible_and_reapplicable(): void
    {
        $migration = require database_path('migrations/2026_08_14_070000_create_periodic_reviews.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('periodic_reviews'));

        $migration->up();
        $this->assertTrue(Schema::hasColumns('periodic_reviews', [
            'user_id', 'period_type', 'period_start', 'period_end', 'completed_at',
        ]));
    }

    /** @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function attributes(User $owner, array $overrides = []): array
    {
        return [
            'user_id' => $owner->id, 'period_type' => 'weekly', 'period_start' => '2026-08-10',
            'period_end' => '2026-08-16', 'period_rating' => 8, 'worked_well' => 'Focused',
            'completed_at' => now(), ...$overrides,
        ];
    }
}
