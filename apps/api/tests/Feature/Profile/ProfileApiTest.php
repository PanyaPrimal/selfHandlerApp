<?php

namespace Tests\Feature\Profile;

class ProfileApiTest extends ProfileTestCase
{
    public function test_profile_requires_authentication(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
        $this->putJson('/api/profile', $this->validPayload())->assertUnauthorized();
        $this->patchJson('/api/profile', ['preferences' => ['theme' => []]])->assertUnauthorized();
    }

    public function test_get_returns_only_the_current_users_full_profile_and_finite_options(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other@example.test');
        $this->createProfile($owner, ['timezone' => 'Europe/Kyiv']);
        $this->createProfile($other, ['timezone' => 'America/New_York', 'date_of_birth' => '1980-01-01']);

        $this->actingAs($owner)->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.user.id', $owner->id)
            ->assertJsonPath('data.timezone', 'Europe/Kyiv')
            ->assertJsonPath('data.date_of_birth', null)
            ->assertJsonPath('options.locales', ['en-GB', 'uk-UA', 'ru-UA'])
            ->assertJsonMissing(['America/New_York', '1980-01-01']);
    }

    public function test_full_put_atomically_updates_name_preferences_and_canonical_inputs(): void
    {
        $user = $this->createUser();
        $this->createProfile($user);

        $this->actingAs($user)->putJson('/api/profile', $this->validPayload())
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Alex Profile')
            ->assertJsonPath('data.height_meters', 1.725)
            ->assertJsonPath('data.weight_grams', 68400)
            ->assertJsonPath('data.calculation_ready', true)
            ->assertJsonPath('data.missing_fields', []);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Alex Profile']);
        $this->assertDatabaseHas('user_profiles', ['user_id' => $user->id, 'timezone' => 'Europe/Kyiv']);
    }

    public function test_invalid_full_put_rolls_back_every_field_and_rejects_owner_injection(): void
    {
        $user = $this->createUser();
        $profile = $this->createProfile($user);

        $this->actingAs($user)->putJson('/api/profile', $this->validPayload([
            'name' => 'Should not persist',
            'timezone' => 'Not/AZone',
            'height_meters' => 4,
            'user_id' => 999,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['timezone', 'height_meters', 'user_id']);

        $this->assertSame($user->name, $user->fresh()->name);
        $this->assertSame($profile->timezone, $profile->fresh()->timezone);
    }

    public function test_bounds_nullable_clears_and_formula_coupling_are_enforced(): void
    {
        $user = $this->createUser();
        $this->createProfile($user);
        $this->actingAs($user);

        $this->putJson('/api/profile', $this->validPayload([
            'bmr_formula' => 'katch_mcardle',
            'body_fat_percentage' => null,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['body_fat_percentage']);

        $this->putJson('/api/profile', $this->validPayload([
            'bmr_formula' => 'katch_mcardle',
            'date_of_birth' => null,
            'sex' => null,
            'height_meters' => null,
            'body_fat_percentage' => 22.5,
        ]))->assertOk()
            ->assertJsonPath('data.calculation_ready', true)
            ->assertJsonPath('data.date_of_birth', null)
            ->assertJsonPath('data.height_meters', null);
    }

    public function test_repeated_full_put_is_idempotent_and_never_creates_another_profile(): void
    {
        $user = $this->createUser();
        $this->createProfile($user);
        $this->actingAs($user);
        $payload = $this->validPayload();

        $this->putJson('/api/profile', $payload)->assertOk();
        $this->putJson('/api/profile', $payload)->assertOk();

        $this->assertDatabaseCount('user_profiles', 1);
        $this->assertDatabaseHas('user_profiles', ['user_id' => $user->id, 'weight_grams' => 68400]);
    }

    public function test_theme_preferences_are_partially_updated_without_touching_profile_fields(): void
    {
        $user = $this->createUser();
        $profile = $this->createProfile($user, ['timezone' => 'Europe/Kyiv']);

        $payload = [
            'preferences' => [
                'theme' => [
                    'scheme' => 'system',
                    'accent' => 'custom',
                    'accent_hex' => '#6D5AC4',
                    'background' => 'paper',
                    'background_hex' => '#ECE9E2',
                    'texture' => false,
                    'mono_numerals' => false,
                    'motion' => 'reduce',
                ],
            ],
        ];

        $this->actingAs($user)->patchJson('/api/profile', $payload)
            ->assertOk()
            ->assertJsonPath('data.theme.scheme', 'system')
            ->assertJsonPath('data.theme.accent_hex', '#6d5ac4')
            ->assertJsonPath('data.user.preferences.theme.motion', 'reduce')
            ->assertJsonPath('data.timezone', 'Europe/Kyiv');

        $this->assertSame('Europe/Kyiv', $profile->fresh()->timezone);
        $expected = $payload['preferences']['theme'];
        $expected['accent_hex'] = '#6d5ac4';
        $expected['background_hex'] = '#ece9e2';
        $this->assertSame($expected, $profile->fresh()->themePreferences());
    }

    public function test_theme_patch_requires_the_complete_bounded_shape_and_rejects_unknown_fields(): void
    {
        $user = $this->createUser();
        $this->createProfile($user);

        $this->actingAs($user)->patchJson('/api/profile', [
            'preferences' => [
                'theme' => [
                    'scheme' => 'sepia',
                    'accent' => 'custom',
                    'accent_hex' => 'not-a-colour',
                    'background' => 'custom',
                    'background_hex' => 'not-a-colour',
                    'texture' => true,
                    'mono_numerals' => true,
                    'motion' => 'system',
                    'user_id' => 999,
                ],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'preferences.theme',
                'preferences.theme.scheme',
                'preferences.theme.accent_hex',
            ]);
    }
}
