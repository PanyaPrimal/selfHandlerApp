<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

class CurrentUser
{
    public function resolve(Request $request): User
    {
        $user = $request->user();

        if ($user) {
            return $user;
        }

        if (app()->environment(['local', 'testing'])) {
            return User::firstOrCreate(
                ['email' => 'local@selfhandler.test'],
                ['name' => 'Local SelfHandler User', 'password' => 'password'],
            );
        }

        abort(401);
    }
}
