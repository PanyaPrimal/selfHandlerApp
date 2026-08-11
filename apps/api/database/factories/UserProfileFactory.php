<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserProfile;
use App\Support\ProfileDefaults;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserProfile> */
class UserProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            ...ProfileDefaults::attributes(),
        ];
    }
}
