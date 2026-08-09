<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Auth;

class AuthenticationTest extends AuthTestCase
{
    public function test_login_normalizes_email_returns_only_safe_identity_and_rotates_the_session(): void
    {
        $user = $this->createUser('alex@example.test');
        $this->withSession(['visitor_marker' => 'before-login']);
        $sessionBefore = session()->getId();

        $response = $this->postJson('/api/auth/login', [
            'email' => '  ALEX@Example.Test  ',
            'password' => self::VALID_PASSWORD,
        ]);

        $this->assertSafeUserResponse($response, $user);
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionBefore, session()->getId());
        $this->assertSafeUserResponse($this->getJson('/api/auth/user'), $user);
    }

    public function test_unknown_email_and_wrong_password_share_the_same_generic_response(): void
    {
        $this->createUser('alex@example.test');

        $wrongPassword = $this->postJson('/api/auth/login', [
            'email' => 'alex@example.test',
            'password' => 'this password is incorrect',
        ]);
        $unknownEmail = $this->postJson('/api/auth/login', [
            'email' => 'missing@example.test',
            'password' => 'this password is incorrect',
        ]);

        $wrongPassword
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The provided credentials are incorrect.',
                'errors' => [
                    'email' => ['The provided credentials are incorrect.'],
                ],
            ]);
        $unknownEmail->assertUnprocessable();
        $this->assertSame($wrongPassword->json(), $unknownEmail->json());
        $this->assertGuest();
    }

    public function test_authenticated_login_returns_conflict_before_validation_and_does_not_switch_accounts(): void
    {
        $current = $this->createUser('current@example.test');
        $other = $this->createUser('other@example.test');
        $this->actingAs($current);

        $this->postJson('/api/auth/login', [
            'email' => $other->email,
            'password' => self::VALID_PASSWORD,
        ])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Already authenticated.']);

        $this->assertAuthenticatedAs($current);
        $this->assertSafeUserResponse($this->getJson('/api/auth/user'), $current);
    }

    public function test_current_user_requires_a_session_and_never_serializes_secrets(): void
    {
        $this->getJson('/api/auth/user')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');

        $user = $this->createUser();
        $this->actingAs($user);

        $response = $this->getJson('/api/auth/user');
        $this->assertSafeUserResponse($response, $user);
        $this->assertStringNotContainsString('password', $response->getContent());
        $this->assertStringNotContainsString('remember_token', $response->getContent());
        $this->assertStringNotContainsString('session', $response->getContent());
    }

    public function test_logout_invalidates_the_current_session_and_rejects_later_protected_requests(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);
        $sessionBefore = session()->getId();

        $this->postJson('/api/auth/logout')->assertNoContent();

        $this->assertGuest();
        $this->assertNotSame($sessionBefore, session()->getId());
        $this->getJson('/api/auth/user')->assertUnauthorized();
        $this->getJson('/api/routines')->assertUnauthorized();
    }

    public function test_unauthenticated_logout_is_rejected(): void
    {
        Auth::logout();

        $this->postJson('/api/auth/logout')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }
}
