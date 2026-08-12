<?php

namespace Tests\Feature\Body;

use App\Models\BodyMeasurement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class BodyTestCase extends TestCase
{
    use RefreshDatabase;

    /** Progress reads "the latest observation on or before today", so today is fixed. */
    protected const TODAY = '2026-08-12';

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
        $user = User::factory()->create(['email' => $email, 'email_verified_at' => null]);
        $user->ensureProfile()->update(['timezone' => $timezone]);
        $user->unsetRelation('profile');

        return $user->fresh();
    }

    protected function measure(User $user, string $metric, string $date, float|string $value): BodyMeasurement
    {
        return BodyMeasurement::create([
            'user_id' => $user->id,
            'metric' => $metric,
            'measured_on' => $date,
            'value' => $value,
        ]);
    }
}
