<?php

namespace Tests\Feature\Integrations;

use App\Models\User;
use App\Services\Integrations\GoogleOAuthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CalendarOAuthStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_state_is_opaque_owner_bound_and_consumed_once(): void
    {
        $owner = User::factory()->create();
        $issued = app(GoogleOAuthState::class)->issue($owner);

        $this->assertGreaterThanOrEqual(43, strlen($issued['state']));
        $this->assertNotSame((string) $owner->id, $issued['state']);
        $this->assertFalse(Cache::has('calendar:google:oauth:'.$issued['state']));
        $this->assertTrue($issued['expires_at']->isFuture());
        $this->assertTrue(app(GoogleOAuthState::class)->consume($issued['state'])->is($owner));

        $this->expectException(ValidationException::class);
        app(GoogleOAuthState::class)->consume($issued['state']);
    }

    public function test_tampered_or_expired_state_fails_closed(): void
    {
        $owner = User::factory()->create();
        $issued = app(GoogleOAuthState::class)->issue($owner);

        $this->travel(11)->minutes();

        foreach ([$issued['state'], $issued['state'].'x', str_repeat('a', 43)] as $state) {
            try {
                app(GoogleOAuthState::class)->consume($state);
                $this->fail('Invalid OAuth state was accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }
}
