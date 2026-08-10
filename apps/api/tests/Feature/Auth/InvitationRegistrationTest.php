<?php

namespace Tests\Feature\Auth;

use App\Models\Invitation;
use App\Models\User;

class InvitationRegistrationTest extends AuthTestCase
{
    public function test_a_valid_invite_code_is_consumed_and_bound_to_the_new_user(): void
    {
        $invitation = $this->createInvitation('K7QP-3M9F-XR2T');

        $response = $this->postJson('/api/auth/register', $this->registrationPayload([
            'email' => 'alex@example.test',
            'invite_code' => 'K7QP-3M9F-XR2T',
        ]));

        $user = User::query()->where('email', 'alex@example.test')->sole();
        $this->assertSafeUserResponse($response, $user, 201);

        $invitation->refresh();
        $this->assertNotNull($invitation->used_at);
        $this->assertSame($user->id, $invitation->used_by);
    }

    public function test_registration_is_rejected_without_an_invite_code(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Alex Example',
            'email' => 'alex@example.test',
            'password' => self::VALID_PASSWORD,
            'password_confirmation' => self::VALID_PASSWORD,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['invite_code']);
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_registration_is_rejected_for_an_unknown_invite_code(): void
    {
        $response = $this->postJson('/api/auth/register', $this->registrationPayload([
            'invite_code' => 'ZZZZ-ZZZZ-ZZZZ',
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors(['invite_code']);
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_an_already_used_invite_code_cannot_be_reused(): void
    {
        $existing = $this->createUser('first@example.test');
        $invitation = $this->createInvitation('USED-CODE-0000');
        $invitation->forceFill(['used_by' => $existing->id, 'used_at' => now()])->save();

        $response = $this->postJson('/api/auth/register', $this->registrationPayload([
            'email' => 'second@example.test',
            'invite_code' => 'USED-CODE-0000',
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors(['invite_code']);
        $this->assertDatabaseMissing('users', ['email' => 'second@example.test']);
        $this->assertGuest();
    }

    public function test_invite_code_is_normalized_to_uppercase_and_trimmed(): void
    {
        $this->createInvitation('K7QP-3M9F-XR2T');

        $response = $this->postJson('/api/auth/register', $this->registrationPayload([
            'email' => 'alex@example.test',
            'invite_code' => '  k7qp-3m9f-xr2t  ',
        ]));

        $response->assertCreated();
        $this->assertDatabaseHas('invitations', [
            'code' => 'K7QP-3M9F-XR2T',
            'used_by' => User::query()->where('email', 'alex@example.test')->sole()->id,
        ]);
    }

    public function test_a_failed_registration_does_not_consume_the_invite_code(): void
    {
        // A duplicate email fails after invite validation; the code must remain usable.
        $this->createUser('taken@example.test');
        $invitation = $this->createInvitation('KEEP-CODE-0000');

        $response = $this->postJson('/api/auth/register', $this->registrationPayload([
            'email' => 'taken@example.test',
            'invite_code' => 'KEEP-CODE-0000',
        ]));

        $response->assertUnprocessable();

        $invitation->refresh();
        $this->assertNull($invitation->used_at);
        $this->assertNull($invitation->used_by);
    }

    public function test_generated_codes_use_the_unambiguous_alphabet_and_format(): void
    {
        $code = Invitation::generateCode();

        $this->assertMatchesRegularExpression('/\A[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}\z/', $code);
    }
}
