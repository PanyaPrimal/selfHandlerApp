<?php

namespace Tests\Feature\Mobile;

use App\Models\Routine;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\PersonalAccessToken;

class MobileRegistrationTest extends MobileTestCase
{
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => '  New Owner  ',
            'email' => '  NEW@EXAMPLE.TEST  ',
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
            'device_name' => '  Armor Mini 20T Pro  ',
        ], $overrides);
    }

    public function test_open_registration_creates_a_profile_and_an_expiring_token_with_only_own_data(): void
    {
        $other = $this->createUser();
        Routine::create(['user_id' => $other->id, 'name' => 'Private routine']);
        $response = $this->postJson('/api/mobile/register', $this->payload())->assertCreated();
        $token = $response->json('data.token');
        $user = User::where('email', 'new@example.test')->sole();
        $response->assertJsonPath('data.user.name', 'New Owner')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonMissingPath('data.user.password');
        $stored = PersonalAccessToken::findToken($token);
        $this->assertSame(['mobile'], $stored->abilities);
        $this->assertSame('Android · Armor Mini 20T Pro', $stored->name);
        $this->assertTrue($stored->expires_at->isFuture());
        $this->assertDatabaseHas('user_profiles', ['user_id' => $user->id]);
        $this->assertDatabaseCount('invitations', 0);
        $this->assertGuest('web');

        $this->withHeaders($this->bearer($token))->getJson('/api/mobile/session')
            ->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->withHeaders($this->bearer($token))->getJson('/api/routines')
            ->assertOk()->assertExactJson(['data' => []]);
        $this->withHeaders($this->bearer($token))->deleteJson('/api/mobile/session')->assertNoContent();
        $this->assertNull(PersonalAccessToken::findToken($token));
    }

    public function test_invalid_registration_creates_neither_account_profile_nor_token(): void
    {
        $this->postJson('/api/mobile/register', $this->payload([
            'email' => 'invalid', 'password_confirmation' => 'mismatch', 'device_name' => '',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['email', 'password', 'device_name']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_profiles', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_duplicate_normalized_email_and_privileged_fields_are_rejected(): void
    {
        $this->createUser('new@example.test');
        $this->postJson('/api/mobile/register', $this->payload(['abilities' => ['*']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'abilities']);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_web_and_mobile_registration_share_the_ip_rate_limit(): void
    {
        config(['auth.registration_attempts_per_minute' => 2]);
        $this->postJson('/api/mobile/register', [])->assertUnprocessable();
        $this->postJson('/api/auth/register', [])->assertUnprocessable();
        $this->postJson('/api/mobile/register', $this->payload())->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_token_creation_failure_rolls_back_account_and_profile(): void
    {
        $event = 'eloquent.creating: '.PersonalAccessToken::class;
        Event::listen($event, fn () => throw new \RuntimeException('Token persistence failed'));
        try {
            $this->postJson('/api/mobile/register', $this->payload())->assertStatus(500);
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_profiles', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_an_authenticated_browser_cannot_replace_its_identity_via_mobile_registration(): void
    {
        $this->actingAs($this->createUser());
        $this->postJson('/api/mobile/register', $this->payload())->assertStatus(409);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_an_authenticated_device_cannot_create_another_identity(): void
    {
        $token = $this->issueToken($this->createUser());
        $this->withHeaders($this->bearer($token))->postJson('/api/mobile/register', $this->payload())
            ->assertStatus(409);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }
}
