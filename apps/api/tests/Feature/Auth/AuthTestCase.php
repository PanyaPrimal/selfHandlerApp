<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class AuthTestCase extends TestCase
{
    use RefreshDatabase;

    protected const VALID_PASSWORD = 'correct horse battery staple';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function registrationPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Alex Example',
            'email' => 'alex@example.test',
            'password' => self::VALID_PASSWORD,
            'password_confirmation' => self::VALID_PASSWORD,
        ], $overrides);
    }

    protected function createUser(
        string $email = 'alex@example.test',
        string $password = self::VALID_PASSWORD,
        string $name = 'Alex Example',
    ): User {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'email_verified_at' => null,
        ]);
    }

    protected function assertSafeUserResponse(TestResponse $response, User $user, int $status = 200): void
    {
        $response
            ->assertStatus($status)
            ->assertExactJson([
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ]);
    }
}
