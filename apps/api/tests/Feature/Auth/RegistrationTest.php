<?php

namespace Tests\Feature\Auth;

use App\Models\Goal;
use App\Models\Routine;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

class RegistrationTest extends AuthTestCase
{
    public function test_a_visitor_registers_a_normalized_account_and_enters_a_rotated_session(): void
    {
        $otherUser = $this->createUser('owner@example.test');
        Routine::create(['user_id' => $otherUser->id, 'name' => 'Private routine']);
        Goal::create(['user_id' => $otherUser->id, 'name' => 'Private goal']);

        $this->withSession(['visitor_marker' => 'before-registration']);
        $sessionBefore = session()->getId();

        $response = $this->postJson('/api/auth/register', $this->registrationPayload([
            'name' => '  Alex Example  ',
            'email' => '  ALEX@Example.Test  ',
        ]));

        $user = User::query()->where('email', 'alex@example.test')->sole();

        $this->assertSafeUserResponse($response, $user, 201);
        $this->assertSame('Alex Example', $user->name);
        $this->assertTrue(Hash::check(self::VALID_PASSWORD, $user->password));
        $this->assertNotSame(self::VALID_PASSWORD, $user->password);
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionBefore, session()->getId());

        $this->assertSafeUserResponse($this->getJson('/api/auth/user'), $user);
        $this->getJson('/api/routines')->assertOk()->assertExactJson(['data' => []]);
        $this->getJson('/api/goals')->assertOk()->assertExactJson(['data' => []]);
        $this->getJson('/api/daily-reviews/2026-08-09')->assertOk()->assertExactJson(['data' => null]);
        $this->getJson('/api/today?date=2026-08-09')
            ->assertOk()
            ->assertJsonPath('summary.scheduled', 0)
            ->assertJsonCount(0, 'routines')
            ->assertJsonCount(0, 'goals')
            ->assertJsonPath('review', null);

        $this->assertDatabaseHas('routines', ['id' => 1, 'user_id' => $otherUser->id]);
        $this->assertDatabaseHas('goals', ['id' => 1, 'user_id' => $otherUser->id]);
    }

    public function test_registration_reports_field_errors_without_creating_an_account_or_session(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => '   ',
            'email' => 'not-an-email',
            'password' => 'too short',
            'password_confirmation' => 'different',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password', 'invite_code']);

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_registration_rejects_a_duplicate_normalized_email(): void
    {
        $existing = $this->createUser('alex@example.test');

        $response = $this->postJson('/api/auth/register', $this->registrationPayload([
            'email' => '  ALEX@EXAMPLE.TEST  ',
        ]));

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseCount('users', 1);
        $this->assertGuest();
        $this->assertDatabaseHas('users', ['id' => $existing->id, 'email' => 'alex@example.test']);
    }

    public function test_authenticated_registration_returns_conflict_before_validation_and_does_not_mutate_identity(): void
    {
        $current = $this->createUser('current@example.test');
        $this->actingAs($current);

        $this->postJson('/api/auth/register', [])
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Already authenticated.']);

        $this->assertDatabaseCount('users', 1);
        $this->assertAuthenticatedAs($current);
    }

    public function test_a_unique_violation_thrown_during_insert_is_mapped_to_a_validation_error(): void
    {
        // Reproduce the race at the insert boundary: validation passes (no
        // existing row yet), but the INSERT itself throws a unique violation
        // because a concurrent request won. The endpoint must map this to a 422
        // email error rather than a 500, and must not leave a session.
        $eventName = 'eloquent.creating: '.User::class;

        Event::listen($eventName, function (User $candidate): void {
            if ($candidate->email === 'race@example.test') {
                throw new UniqueConstraintViolationException(
                    'sqlite',
                    'insert into "users"',
                    [],
                    new \PDOException('UNIQUE constraint failed: users.email'),
                );
            }
        });

        try {
            $response = $this->postJson('/api/auth/register', $this->registrationPayload([
                'email' => 'race@example.test',
            ]));
        } finally {
            Event::forget($eventName);
        }

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        // The transaction rolled back: no user row and no consumed invite.
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseMissing('invitations', [
            'used_at' => now()->toDateTimeString(),
        ]);
        $this->assertGuest();
    }

    public function test_database_unique_index_is_the_duplicate_concurrency_backstop(): void
    {
        $this->createUser('race@example.test');

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('users')->insert([
            'name' => 'Concurrent loser',
            'email' => 'race@example.test',
            'password' => Hash::make(self::VALID_PASSWORD),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
