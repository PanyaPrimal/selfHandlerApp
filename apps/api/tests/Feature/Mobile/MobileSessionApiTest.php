<?php

namespace Tests\Feature\Mobile;

use App\Models\Routine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class MobileSessionApiTest extends MobileTestCase
{
    public function test_existing_account_credentials_create_one_safe_expiring_mobile_session(): void
    {
        CarbonImmutable::setTestNow('2026-08-13 09:00:00 UTC');
        $user = $this->createUser('owner@example.test');

        $response = $this->postJson('/api/mobile/session', [
            'email' => '  OWNER@EXAMPLE.TEST ',
            'password' => 'correct horse battery staple',
            'device_name' => '  Pixel 9  ',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.expires_at', '2026-09-12T09:00:00.000000Z')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', 'owner@example.test')
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token');

        $plainText = $response->json('data.token');
        $this->assertIsString($plainText);
        [$id, $secret] = explode('|', $plainText, 2);
        $token = PersonalAccessToken::query()->findOrFail((int) $id);

        $this->assertSame('Android · Pixel 9', $token->name);
        $this->assertSame(['mobile'], $token->abilities);
        $this->assertSame(hash('sha256', $secret), $token->token);
        $this->assertNotSame($plainText, $token->token);
        $this->assertSame('2026-09-12 09:00:00', $token->expires_at->format('Y-m-d H:i:s'));
        $this->assertGuest('web');
    }

    public function test_current_session_restores_safe_user_without_returning_the_token_again(): void
    {
        $user = $this->createUser();
        $token = $this->issueToken($user);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/mobile/session')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.expires_at', $token->accessToken->expires_at->toISOString())
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.user.password');
    }

    public function test_logout_revokes_only_the_current_mobile_token(): void
    {
        $user = $this->createUser();
        $current = $this->issueToken($user, name: 'Android · Current');
        $other = $this->issueToken($user, name: 'Android · Tablet');

        $this->withHeaders($this->bearer($current))
            ->deleteJson('/api/mobile/session')
            ->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $other->accessToken->id]);

        $this->withHeaders($this->bearer($current))
            ->getJson('/api/mobile/session')
            ->assertUnauthorized();
        $this->withHeaders($this->bearer($other))
            ->getJson('/api/mobile/session')
            ->assertOk();
    }

    public function test_expired_token_is_rejected_and_owner_scoped_domain_routes_accept_a_valid_one(): void
    {
        CarbonImmutable::setTestNow('2026-08-13 09:00:00 UTC');
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        Routine::create(['user_id' => $owner->id, 'name' => 'Owner routine']);
        Routine::create(['user_id' => $other->id, 'name' => 'Other routine']);
        $valid = $this->issueToken($owner);
        $expired = $this->issueToken($owner, expiresAt: CarbonImmutable::now()->subSecond());

        $this->withHeaders($this->bearer($expired))
            ->getJson('/api/mobile/session')
            ->assertUnauthorized();

        $this->withHeaders($this->bearer($valid))
            ->getJson('/api/routines')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Owner routine');

        $this->assertTrue(Hash::check('correct horse battery staple', $owner->password));
    }
}
