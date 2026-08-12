<?php

namespace Tests\Feature\Profile;

class ProfilePreferenceApiTest extends ProfileTestCase
{
    /** @return array<string, mixed> */
    private function theme(array $overrides = []): array
    {
        return [
            'scheme' => 'dark',
            'accent' => 'custom',
            'accent_hex' => '#6D5AC4',
            'background' => 'custom',
            'background_hex' => '#345678',
            'texture' => false,
            'mono_numerals' => false,
            'motion' => 'reduce',
            ...$overrides,
        ];
    }

    public function test_locale_can_be_patched_without_changing_theme_or_profile_draft_fields(): void
    {
        $user = $this->createUser();
        $profile = $this->createProfile($user, [
            'locale' => 'en-GB',
            'timezone' => 'Europe/Kyiv',
            'weight_grams' => 72000,
            'theme_preferences' => $this->theme(['scheme' => 'light']),
        ]);
        $theme = $profile->themePreferences();

        $this->actingAs($user)->patchJson('/api/profile', [
            'preferences' => ['locale' => 'uk-UA'],
        ])->assertOk()
            ->assertJsonPath('data.locale', 'uk-UA')
            ->assertJsonPath('data.user.preferences.locale', 'uk-UA')
            ->assertJsonPath('data.timezone', 'Europe/Kyiv')
            ->assertJsonPath('data.weight_grams', 72000)
            ->assertJsonPath('data.theme.background', 'custom');

        $fresh = $profile->fresh();
        $this->assertSame('uk-UA', $fresh->locale);
        $this->assertSame('Europe/Kyiv', $fresh->timezone);
        $this->assertSame(72000, $fresh->weight_grams);
        $this->assertSame($theme, $fresh->themePreferences());
    }

    public function test_locale_and_complete_theme_can_be_updated_atomically(): void
    {
        $user = $this->createUser();
        $profile = $this->createProfile($user);

        $this->actingAs($user)->patchJson('/api/profile', [
            'preferences' => [
                'locale' => 'ru-UA',
                'theme' => $this->theme(),
            ],
        ])->assertOk()
            ->assertJsonPath('data.locale', 'ru-UA')
            ->assertJsonPath('data.theme.scheme', 'dark')
            ->assertJsonPath('data.theme.accent_hex', '#6d5ac4')
            ->assertJsonPath('data.theme.background_hex', '#345678');

        $this->assertSame('ru-UA', $profile->fresh()->locale);
        $this->assertSame('#6d5ac4', $profile->fresh()->themePreferences()['accent_hex']);
    }

    public function test_invalid_partial_preferences_are_atomic_and_unknown_keys_are_rejected(): void
    {
        $user = $this->createUser();
        $profile = $this->createProfile($user, ['locale' => 'en-GB']);
        $before = $profile->fresh()->getAttributes();

        $this->actingAs($user)->patchJson('/api/profile', [
            'preferences' => [
                'locale' => 'pl-PL',
                'theme' => $this->theme(['background_hex' => 'red']),
                'timezone' => 'Europe/Kyiv',
            ],
            'user_id' => 999,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'preferences',
                'preferences.locale',
                'preferences.theme.background_hex',
                'user_id',
            ]);

        $this->assertSame($before, $profile->fresh()->getAttributes());
    }

    public function test_preferences_require_at_least_one_supported_member_and_complete_theme(): void
    {
        $user = $this->createUser();
        $this->createProfile($user);
        $this->actingAs($user);

        $this->patchJson('/api/profile', ['preferences' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['preferences']);

        $this->patchJson('/api/profile', [
            'preferences' => ['theme' => ['scheme' => 'dark']],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'preferences.theme.accent',
                'preferences.theme.accent_hex',
                'preferences.theme.background',
                'preferences.theme.background_hex',
                'preferences.theme.texture',
                'preferences.theme.mono_numerals',
                'preferences.theme.motion',
            ]);
    }

    public function test_legacy_theme_json_is_normalized_with_background_defaults(): void
    {
        $user = $this->createUser();
        $this->createProfile($user, [
            'theme_preferences' => [
                'scheme' => 'dark',
                'accent' => 'slate',
                'accent_hex' => '#123456',
                'texture' => true,
                'mono_numerals' => true,
                'motion' => 'system',
            ],
        ]);

        $this->actingAs($user)->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.theme.background', 'paper')
            ->assertJsonPath('data.theme.background_hex', '#ece9e2')
            ->assertJsonPath('data.user.preferences.theme.background', 'paper');
    }

    public function test_authenticated_profile_locale_overrides_accept_language_for_validation(): void
    {
        $user = $this->createUser();
        $this->createProfile($user, ['locale' => 'uk-UA']);

        $response = $this->actingAs($user)
            ->withHeader('Accept-Language', 'ru-UA')
            ->patchJson('/api/profile', ['preferences' => ['locale' => 'invalid']]);

        $response->assertUnprocessable();
        $this->assertStringContainsStringIgnoringCase(
            'вибране значення',
            $response->json('errors')['preferences.locale'][0],
        );
    }

    public function test_one_user_cannot_patch_another_users_preferences(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser('other-preferences@example.test');
        $ownerProfile = $this->createProfile($owner, ['locale' => 'en-GB']);
        $this->createProfile($other, ['locale' => 'ru-UA']);

        $this->actingAs($owner)->patchJson('/api/profile', [
            'preferences' => ['locale' => 'uk-UA'],
        ])->assertOk()->assertJsonPath('data.user.id', $owner->id);

        $this->assertSame('uk-UA', $ownerProfile->fresh()->locale);
        $this->assertDatabaseHas('user_profiles', ['user_id' => $other->id, 'locale' => 'ru-UA']);
    }
}
