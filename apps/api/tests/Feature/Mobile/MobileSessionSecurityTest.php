<?php

namespace Tests\Feature\Mobile;

class MobileSessionSecurityTest extends MobileTestCase
{
    public function test_unknown_email_and_wrong_password_share_generic_feedback(): void
    {
        $this->createUser('known@example.test');

        $unknown = $this->postJson('/api/mobile/session', [
            'email' => 'unknown@example.test',
            'password' => 'wrong password',
            'device_name' => 'Pixel 9',
        ])->assertUnprocessable();

        $wrong = $this->postJson('/api/mobile/session', [
            'email' => 'known@example.test',
            'password' => 'wrong password',
            'device_name' => 'Pixel 9',
        ])->assertUnprocessable();

        $this->assertSame($unknown->json('message'), $wrong->json('message'));
        $this->assertSame($unknown->json('errors.email'), $wrong->json('errors.email'));
        $this->assertStringNotContainsString('unknown@example.test', $unknown->getContent());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_mobile_login_is_rate_limited_after_five_failures(): void
    {
        $this->createUser();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.44']);
        $payload = [
            'email' => 'owner@example.test',
            'password' => 'wrong password',
            'device_name' => 'Pixel 9',
        ];

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/mobile/session', $payload)->assertUnprocessable();
        }

        $this->postJson('/api/mobile/session', $payload)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Too many login attempts. Please try again later.');
    }

    public function test_mobile_feedback_uses_requested_locale_and_rejects_unknown_fields(): void
    {
        $response = $this->withHeader('Accept-Language', 'uk-UA')
            ->postJson('/api/mobile/session', [
                'email' => 'not-an-email',
                'password' => '',
                'device_name' => '',
                'token' => 'must never be accepted',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password', 'device_name', 'token']);

        $this->assertStringContainsString('електрон', mb_strtolower($response->getContent()));
        $this->assertStringNotContainsString('must never be accepted', $response->getContent());

        $this->postJson('/api/mobile/session', [
            'email' => 'owner@example.test',
            'password' => 'correct horse battery staple',
            'device_name' => str_repeat('x', 65),
        ])->assertUnprocessable()->assertJsonValidationErrors('device_name');
    }

    public function test_token_specific_routes_require_a_current_mobile_bearer_not_a_cookie_or_other_ability(): void
    {
        $user = $this->createUser();
        $otherAbility = $this->issueToken($user, ['integration']);

        $this->actingAs($user)
            ->getJson('/api/mobile/session')
            ->assertForbidden();
        $this->actingAs($user)
            ->deleteJson('/api/mobile/session')
            ->assertForbidden();

        $this->withHeaders($this->bearer($otherAbility))
            ->getJson('/api/mobile/session')
            ->assertForbidden();
        $this->withToken('not-a-real-token')
            ->getJson('/api/mobile/session')
            ->assertUnauthorized();
    }

    public function test_web_login_remains_a_cookie_session_and_creates_no_api_token(): void
    {
        $user = $this->createUser();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'correct horse battery staple',
        ])->assertOk()->assertJsonPath('data.id', $user->id);

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->getJson('/api/auth/user')->assertOk()->assertJsonMissingPath('data.token');
    }
}
