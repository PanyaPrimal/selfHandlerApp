<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use App\Models\UserProfile;
use App\Support\ProfileDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class ProfileTestCase extends TestCase
{
    use RefreshDatabase;

    protected function createUser(string $email = 'profile@example.test'): User
    {
        return User::factory()->create(['email' => $email]);
    }

    protected function createProfile(User $user, array $attributes = []): UserProfile
    {
        return UserProfile::create([
            'user_id' => $user->id,
            ...ProfileDefaults::attributes(),
            ...$attributes,
        ]);
    }

    /** @return array<string, mixed> */
    protected function validPayload(array $overrides = []): array
    {
        return [
            'name' => 'Alex Profile',
            'timezone' => 'Europe/Kyiv',
            'locale' => 'uk-UA',
            'unit_system' => 'metric',
            'base_currency' => 'UAH',
            'recommendation_tone' => 'friendly',
            'bmr_formula' => 'mifflin_st_jeor',
            'date_of_birth' => '1990-06-15',
            'sex' => 'female',
            'height_meters' => 1.725,
            'weight_grams' => 68400,
            'body_fat_percentage' => null,
            'baseline_activity' => 'moderate',
            ...$overrides,
        ];
    }
}
