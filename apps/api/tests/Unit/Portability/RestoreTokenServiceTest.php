<?php

namespace Tests\Unit\Portability;

use App\Exceptions\PortabilityException;
use App\Models\User;
use App\Services\Portability\RestoreTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestoreTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_is_valid_before_but_not_at_its_expiry_instant(): void
    {
        $issuedAt = CarbonImmutable::parse('2026-08-14T10:00:00Z');
        $this->travelTo($issuedAt);
        $user = User::factory()->create();
        $digest = str_repeat('a', 64);
        $service = app(RestoreTokenService::class);
        $token = $service->issue($user, $digest)['token'];
        $ttl = (int) config('portability.token_ttl_seconds');

        $this->travelTo($issuedAt->addSeconds($ttl - 1));
        $service->verify($token, $user, $digest);
        $this->addToAssertionCount(1);

        $this->travelTo($issuedAt->addSeconds($ttl));
        $this->expectException(PortabilityException::class);
        $this->expectExceptionMessage('restore_token_invalid');
        $service->verify($token, $user, $digest);
    }
}
