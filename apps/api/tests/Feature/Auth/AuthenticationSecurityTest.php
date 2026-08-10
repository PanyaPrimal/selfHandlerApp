<?php

namespace Tests\Feature\Auth;

class AuthenticationSecurityTest extends AuthTestCase
{
    public function test_csrf_endpoint_issues_a_readable_token_and_an_httponly_session_cookie(): void
    {
        $response = $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $cookies = collect($response->headers->getCookies())->keyBy(
            static fn ($cookie): string => $cookie->getName(),
        );

        $this->assertTrue($cookies->has('XSRF-TOKEN'));
        $this->assertTrue($cookies->has('selfhandler_session'));
        $this->assertFalse($cookies->get('XSRF-TOKEN')->isHttpOnly());
        $this->assertTrue($cookies->get('selfhandler_session')->isHttpOnly());
        $this->assertSame('lax', $cookies->get('selfhandler_session')->getSameSite());
    }

    public function test_every_current_domain_route_rejects_an_anonymous_request(): void
    {
        $cases = [
            ['GET', '/api/today?date=2026-08-09', []],
            ['GET', '/api/routines', []],
            ['POST', '/api/routines', ['name' => 'Anonymous routine']],
            ['PATCH', '/api/routines/999999', ['name' => 'Anonymous patch']],
            ['PUT', '/api/routines/999999/logs/2026-08-09', ['status' => 'done']],
            ['DELETE', '/api/routines/999999/logs/2026-08-09', []],
            ['GET', '/api/daily-reviews/2026-08-09', []],
            ['PUT', '/api/daily-reviews/2026-08-09', ['mood' => 7]],
            ['GET', '/api/goals', []],
            ['POST', '/api/goals', ['name' => 'Anonymous goal']],
            ['PATCH', '/api/goals/999999', ['name' => 'Anonymous patch']],
            ['POST', '/api/goals/999999/routines/999999', []],
            ['DELETE', '/api/goals/999999/routines/999999', []],
        ];

        foreach ($cases as [$method, $uri, $payload]) {
            $this->json($method, $uri, $payload)
                ->assertUnauthorized()
                ->assertJsonPath('message', 'Unauthenticated.');
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('routines', 0);
        $this->assertDatabaseCount('goals', 0);
        $this->assertDatabaseCount('routine_logs', 0);
        $this->assertDatabaseCount('daily_reviews', 0);
    }

    public function test_api_routes_return_json_unauthorized_without_an_accept_header(): void
    {
        $this->get('/api/auth/user')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->get('/api/routines')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_unsafe_auth_and_domain_requests_without_csrf_are_rejected_before_mutation(): void
    {
        $this->app['env'] = 'production';
        config(['app.env' => 'production']);

        $this->postJson('/api/auth/register', $this->registrationPayload())
            ->assertStatus(419)
            ->assertJsonPath('message', 'CSRF token mismatch.');

        $this->withHeader('Origin', 'http://127.0.0.1:5173')
            ->postJson('/api/routines', ['name' => 'Must not be created'])
            ->assertStatus(419)
            ->assertJsonPath('message', 'CSRF token mismatch.');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('routines', 0);
    }

    public function test_bearer_credentials_are_not_accepted_or_looked_up_in_cookie_only_mode(): void
    {
        $this->withToken('not-a-supported-credential')
            ->getJson('/api/routines')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_login_is_limited_after_five_failures_without_disclosing_account_existence(): void
    {
        $this->createUser('alex@example.test');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => 'alex@example.test',
                'password' => 'this password is incorrect',
            ])->assertUnprocessable();
        }

        $response = $this->postJson('/api/auth/login', [
            'email' => 'alex@example.test',
            'password' => 'this password is incorrect',
        ]);

        $response
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Too many login attempts. Please try again later.');
        $this->assertStringNotContainsString('alex@example.test', $response->getContent());
        $this->assertGuest();
    }

    public function test_successful_login_clears_the_failure_window(): void
    {
        $user = $this->createUser('alex@example.test');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.11']);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'this password is incorrect',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => self::VALID_PASSWORD,
        ])->assertOk();
        $this->postJson('/api/auth/logout')->assertNoContent();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'this password is incorrect',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'this password is incorrect',
        ])->assertTooManyRequests();
    }

    public function test_registration_is_limited_by_ip_after_three_requests(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.12']);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson('/api/auth/register', [])->assertUnprocessable();
        }

        $response = $this->postJson('/api/auth/register', []);

        $response
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');
        $this->assertDatabaseCount('users', 0);
    }
}
