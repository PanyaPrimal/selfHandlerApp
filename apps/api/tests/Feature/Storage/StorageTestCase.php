<?php

namespace Tests\Feature\Storage;

use App\Models\Item;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class StorageTestCase extends TestCase
{
    use RefreshDatabase;

    protected function createUser(string $email = 'owner@example.test'): User
    {
        return User::factory()->create(['email' => $email, 'email_verified_at' => null]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createItem(User $user, array $attributes = []): Item
    {
        return Item::create([
            'user_id' => $user->id,
            'title' => 'Captured thing',
            ...$attributes,
        ]);
    }

    protected function createProject(User $user, string $name = 'Renovation'): Project
    {
        return Project::create(['user_id' => $user->id, 'name' => $name]);
    }
}
