<?php

namespace Tests\Feature\Auth;

class LocaleFeedbackTest extends AuthTestCase
{
    public function test_guest_registration_validation_uses_requested_russian_locale(): void
    {
        $response = $this->withHeader('Accept-Language', 'ru-UA')
            ->postJson('/api/auth/register', [
                'name' => '',
                'email' => 'not-an-email',
                'password' => 'short',
                'password_confirmation' => 'different',
                'invite_code' => '',
            ]);

        $response->assertUnprocessable();
        $this->assertStringContainsString('обязател', $response->json('errors.name.0'));
        $this->assertStringContainsString('имя', $response->json('errors.name.0'));
        $this->assertStringContainsString('код приглашения', $response->json('errors.invite_code.0'));
    }

    public function test_guest_login_feedback_uses_requested_ukrainian_locale(): void
    {
        $user = $this->createUser();

        $response = $this->withHeader('Accept-Language', 'uk-UA')
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong password',
            ]);

        $response->assertUnprocessable();
        $this->assertStringContainsString('Надані облікові дані', $response->json('errors.email.0'));
    }

    public function test_authenticated_domain_refusal_uses_profile_locale_instead_of_header(): void
    {
        $user = $this->createUser();
        $user->ensureProfile()->forceFill(['locale' => 'ru-UA'])->save();

        $response = $this->actingAs($user)
            ->withHeader('Accept-Language', 'en-GB')
            ->postJson('/api/storage/items', ['title' => '   ']);

        $response->assertUnprocessable();
        $this->assertStringContainsString('обязательно', $response->json('errors.title.0'));
        $this->assertStringContainsString('название', $response->json('errors.title.0'));
    }
}
