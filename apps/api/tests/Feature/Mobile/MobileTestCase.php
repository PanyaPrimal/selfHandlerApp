<?php

namespace Tests\Feature\Mobile;

use App\Models\InAppNotification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\NewAccessToken;
use Tests\TestCase;

abstract class MobileTestCase extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function createUser(
        string $email = 'owner@example.test',
        string $locale = 'en-GB',
        string $password = 'correct horse battery staple',
    ): User {
        $user = User::factory()->create([
            'email' => $email,
            'password' => $password,
            'email_verified_at' => null,
        ]);
        $user->ensureProfile()->update(['locale' => $locale]);
        $user->unsetRelation('profile');

        return $user->fresh();
    }

    /** @param list<string> $abilities */
    protected function issueToken(
        User $user,
        array $abilities = ['mobile'],
        ?CarbonImmutable $expiresAt = null,
        string $name = 'Android · Pixel 9',
    ): NewAccessToken {
        return $user->createToken(
            $name,
            $abilities,
            $expiresAt ?? CarbonImmutable::now()->addDays(30),
        );
    }

    protected function bearer(NewAccessToken|string $token): array
    {
        $value = $token instanceof NewAccessToken ? $token->plainTextToken : $token;

        return ['Authorization' => 'Bearer '.$value];
    }

    protected function createDeliveredNotification(User $user, array $attributes = []): InAppNotification
    {
        return InAppNotification::create([
            'user_id' => $user->id,
            'source_type' => InAppNotification::SOURCE_DAILY_DIGEST,
            'source_id' => 20260813 + InAppNotification::query()->count(),
            'type' => InAppNotification::TYPE_DAILY_DIGEST,
            'category' => InAppNotification::CATEGORY_ROUTINE,
            'title' => 'Daily overview',
            'body' => 'You have one item planned.',
            'action_url' => '/notifications',
            'content' => ['date' => '2026-08-13'],
            'scheduled_at' => now()->subMinute(),
            'status' => InAppNotification::STATUS_SENT,
            'channels' => ['in_app'],
            'escalation_count' => 0,
            'max_escalations' => 0,
            'sent_at' => now(),
            ...$attributes,
        ]);
    }
}
