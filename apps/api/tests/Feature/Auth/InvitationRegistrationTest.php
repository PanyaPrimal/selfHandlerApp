<?php

namespace Tests\Feature\Auth;

use App\Models\Invitation;

class InvitationRegistrationTest extends AuthTestCase
{
    public function test_registration_is_open_without_invitation_rows(): void
    {
        $this->assertDatabaseCount('invitations', 0);
        $this->postJson('/api/auth/register', $this->registrationPayload())->assertCreated();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_legacy_web_clients_can_send_an_obsolete_code_without_consuming_it(): void
    {
        $invitation = Invitation::create(['code' => 'KEEP-CODE-0000']);
        $this->postJson('/api/auth/register', $this->registrationPayload([
            'invite_code' => $invitation->code,
        ]))->assertCreated();
        $this->assertNull($invitation->fresh()->used_at);
        $this->assertNull($invitation->fresh()->used_by);
    }

    public function test_an_unknown_legacy_code_does_not_gate_open_registration(): void
    {
        $this->postJson('/api/auth/register', $this->registrationPayload([
            'invite_code' => 'obsolete-code',
        ]))->assertCreated();
    }
}
