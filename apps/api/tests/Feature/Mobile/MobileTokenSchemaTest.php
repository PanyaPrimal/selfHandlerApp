<?php

namespace Tests\Feature\Mobile;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;

class MobileTokenSchemaTest extends MobileTestCase
{
    public function test_standard_expiring_personal_access_token_schema_and_user_trait_exist(): void
    {
        $this->assertTrue(Schema::hasTable('personal_access_tokens'));
        $this->assertTrue(Schema::hasColumns('personal_access_tokens', [
            'id',
            'tokenable_type',
            'tokenable_id',
            'name',
            'token',
            'abilities',
            'last_used_at',
            'expires_at',
            'created_at',
            'updated_at',
        ]));
        $this->assertContains(HasApiTokens::class, class_uses_recursive(User::class));

        $indexes = collect(DB::select("PRAGMA index_list('personal_access_tokens')"))
            ->pluck('name')
            ->all();
        $this->assertTrue(collect($indexes)->contains(
            fn (string $name): bool => str_contains($name, 'tokenable'),
        ));
        $this->assertTrue(collect($indexes)->contains(
            fn (string $name): bool => str_contains($name, 'expires_at'),
        ));
    }

    public function test_user_deletion_removes_mobile_tokens(): void
    {
        $user = $this->createUser();
        $this->issueToken($user);

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $user->delete();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
