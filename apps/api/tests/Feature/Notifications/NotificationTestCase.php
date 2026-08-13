<?php

namespace Tests\Feature\Notifications;

use App\Models\InAppNotification;
use App\Models\Item;
use App\Models\PlannedOccurrence;
use App\Models\Routine;
use App\Models\User;
use App\Services\RoutineRecurrence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class NotificationTestCase extends TestCase
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

    protected function createUser(
        string $email = 'owner@example.test',
        string $timezone = 'UTC',
        string $locale = 'en-GB',
    ): User {
        $user = User::factory()->create(['email' => $email, 'email_verified_at' => null]);
        $user->ensureProfile()->update(['timezone' => $timezone, 'locale' => $locale]);
        $user->unsetRelation('profile');

        return $user->fresh();
    }

    protected function createRoutine(User $user, array $schedule = [], string $name = 'Morning walk'): Routine
    {
        $routine = Routine::create(['user_id' => $user->id, 'name' => $name]);

        app(RoutineRecurrence::class)->apply(
            $routine,
            $user,
            ['schedule_type' => 'daily', ...$schedule],
            [],
        );

        return $routine->fresh(['recurringRule']);
    }

    protected function occurrenceOn(Routine $routine, string $date = self::TODAY): PlannedOccurrence
    {
        return PlannedOccurrence::query()
            ->where('recurring_rule_id', $routine->recurringRule->id)
            ->where('occurrence_date', $date)
            ->firstOrFail();
    }

    protected function createItem(User $user, array $attributes = []): Item
    {
        return Item::create([
            'user_id' => $user->id,
            'title' => 'Pay dentist',
            'type' => Item::TYPE_TASK,
            'status' => Item::STATUS_ACTIVE,
            'priority' => 'high',
            'due_on' => self::TODAY,
            ...$attributes,
        ]);
    }

    protected function createDelivered(User $user, array $attributes = []): InAppNotification
    {
        return InAppNotification::create([
            'user_id' => $user->id,
            'source_type' => InAppNotification::SOURCE_PLANNED_OCCURRENCE,
            'source_id' => 1000 + InAppNotification::query()->count(),
            'type' => InAppNotification::TYPE_ROUTINE_REMINDER,
            'category' => InAppNotification::CATEGORY_ROUTINE,
            'title' => 'Routine reminder',
            'body' => 'Morning walk is planned now.',
            'action_url' => '/planner?date='.self::TODAY,
            'content' => ['title' => 'Morning walk', 'date' => self::TODAY],
            'scheduled_at' => now()->subMinute(),
            'status' => InAppNotification::STATUS_SENT,
            'channels' => ['in_app'],
            'escalation_count' => 0,
            'max_escalations' => 2,
            'sent_at' => now(),
            ...$attributes,
        ]);
    }
}
