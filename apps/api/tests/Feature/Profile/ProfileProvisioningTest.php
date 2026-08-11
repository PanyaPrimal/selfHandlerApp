<?php

namespace Tests\Feature\Profile;

use App\Models\Invitation;
use App\Models\User;

class ProfileProvisioningTest extends ProfileTestCase
{
    public function test_registration_provisions_defaults_in_the_same_successful_flow(): void
    {
        Invitation::create(['code' => 'PROF-TEST-2026']);

        $this->postJson('/api/auth/register', [
            'name' => 'New Profile',
            'email' => 'new@example.test',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
            'invite_code' => 'PROF-TEST-2026',
        ])->assertCreated()
            ->assertJsonPath('data.preferences.timezone', 'UTC')
            ->assertJsonPath('data.preferences.calculation_ready', false);

        $user = User::where('email', 'new@example.test')->sole();
        $this->assertDatabaseHas('user_profiles', ['user_id' => $user->id]);
    }

    public function test_current_user_repairs_a_missing_profile_without_exposing_private_inputs(): void
    {
        $user = $this->createUser();
        $this->actingAs($user)
            ->getJson('/api/auth/user')
            ->assertOk()
            ->assertJsonMissing(['date_of_birth'])
            ->assertJsonStructure(['data' => ['preferences' => [
                'timezone', 'locale', 'unit_system', 'base_currency', 'recommendation_tone',
                'bmr_formula', 'calculation_ready',
            ]]]);

        $this->assertDatabaseHas('user_profiles', ['user_id' => $user->id]);
    }
}
