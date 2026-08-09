<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class HealthTest extends TestCase
{
    private const RELEASE = '0123456789abcdef0123456789abcdef01234567';

    public function test_readiness_returns_only_status_and_release_when_database_is_available(): void
    {
        config()->set('app.release', self::RELEASE);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'release' => self::RELEASE,
            ]);
    }

    public function test_readiness_is_non_secret_when_database_is_unavailable(): void
    {
        config()->set('app.release', self::RELEASE);
        DB::shouldReceive('select')
            ->once()
            ->with('SELECT 1')
            ->andThrow(new RuntimeException('database-password=must-not-leak'));

        $response = $this->getJson('/api/health');

        $response
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'unavailable',
                'release' => self::RELEASE,
            ]);
        $this->assertStringNotContainsString('database-password', $response->getContent());
    }

    public function test_invalid_release_configuration_fails_readiness_with_contract_safe_identity(): void
    {
        config()->set('app.release', 'not-a-source-revision');

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'unavailable',
                'release' => str_repeat('0', 40),
            ]);
    }
}
