<?php

namespace Tests\Feature\Supplements;

use App\Models\PlannedOccurrence;
use App\Models\Supplement;
use App\Models\SupplementCourse;
use App\Models\User;
use App\Services\SupplementCourseService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class SupplementTestCase extends TestCase
{
    use RefreshDatabase;

    protected const TODAY = '2026-08-13';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(self::TODAY.' 09:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function createUser(string $email = 'owner@example.test', string $timezone = 'UTC'): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->ensureProfile()->update(['timezone' => $timezone, 'locale' => 'en-GB']);
        $user->unsetRelation('profile');

        return $user->fresh();
    }

    /** @param array<string, mixed> $attributes */
    protected function createSupplement(User $user, array $attributes = []): Supplement
    {
        return Supplement::create([
            'user_id' => $user->id, 'name' => 'Capsules', 'category' => 'vitamin', 'form' => 'capsule',
            'stock_unit' => 'piece', 'preferred_display_unit' => 'piece',
            'usual_dose_quantity' => '1', 'package_quantity' => '30', ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function createCourse(User $user, ?Supplement $supplement = null, array $attributes = []): SupplementCourse
    {
        $supplement ??= $this->createSupplement($user);

        return app(SupplementCourseService::class)->create($user, [
            'supplement_id' => $supplement->id, 'goal_id' => null, 'name' => 'Daily course',
            'dose_quantity' => '1', 'dose_display_unit' => 'piece', 'starts_on' => self::TODAY,
            'ends_on' => '2026-08-20', 'is_active' => true,
            'schedule' => [
                'frequency' => 'daily', 'interval_count' => 1, 'weekdays' => [], 'cycle' => null,
                'slots' => [['slot' => 'morning', 'time' => '08:00', 'intake_context' => 'with_food']],
            ],
            ...$attributes,
        ]);
    }

    protected function occurrence(SupplementCourse $course, string $date = self::TODAY, string $slot = 'morning'): PlannedOccurrence
    {
        return PlannedOccurrence::query()
            ->where('recurring_rule_id', $course->recurringRule()->value('id'))
            ->where('occurrence_date', $date)->where('slot', $slot)->sole();
    }
}
