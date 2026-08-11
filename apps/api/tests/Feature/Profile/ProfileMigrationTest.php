<?php

namespace Tests\Feature\Profile;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

class ProfileMigrationTest extends ProfileTestCase
{
    public function test_migration_preserves_existing_users_and_backfills_one_deterministic_profile(): void
    {
        $user = $this->createUser();
        $migration = require database_path('migrations/2026_08_11_120000_create_user_profiles_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('user_profiles'));
        $migration->up();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
        $this->assertDatabaseHas('user_profiles', [
            'user_id' => $user->id,
            'timezone' => 'UTC',
            'locale' => 'en-GB',
            'unit_system' => 'metric',
            'base_currency' => 'UAH',
            'recommendation_tone' => 'neutral',
            'bmr_formula' => 'mifflin_st_jeor',
        ]);
        $this->assertDatabaseCount('user_profiles', 1);
    }

    public function test_database_rejects_more_than_one_profile_for_the_same_user(): void
    {
        $user = $this->createUser();
        $this->createProfile($user);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->createProfile($user);
    }
}
