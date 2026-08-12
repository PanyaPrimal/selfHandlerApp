<?php

namespace Tests\Feature\Auth;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class AuthTestCase extends TestCase
{
    use RefreshDatabase;

    protected const VALID_PASSWORD = 'correct horse battery staple';

    /**
     * Create a fresh, unused invite code and return it.
     */
    protected function createInvitation(?string $code = null): Invitation
    {
        return Invitation::create([
            'code' => $code ?? Invitation::generateCode(),
        ]);
    }

    /**
     * Build a valid registration payload, minting a fresh invite code unless
     * the caller supplied one.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function registrationPayload(array $overrides = []): array
    {
        $inviteCode = $overrides['invite_code'] ?? $this->createInvitation()->code;
        unset($overrides['invite_code']);

        return array_replace([
            'name' => 'Alex Example',
            'email' => 'alex@example.test',
            'password' => self::VALID_PASSWORD,
            'password_confirmation' => self::VALID_PASSWORD,
            'invite_code' => $inviteCode,
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
                    'preferences' => [
                        'timezone' => 'UTC',
                        'locale' => 'en-GB',
                        'unit_system' => 'metric',
                        'base_currency' => 'UAH',
                        'recommendation_tone' => 'neutral',
                        'bmr_formula' => 'mifflin_st_jeor',
                        'calculation_ready' => false,
                        'theme' => [
                            'scheme' => 'light',
                            'accent' => 'forest',
                            'accent_hex' => '#6d5ac4',
                            'texture' => true,
                            'mono_numerals' => true,
                            'motion' => 'system',
                        ],
                    ],
                ],
            ]);
    }
}
