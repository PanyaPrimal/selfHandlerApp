<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterAccount
{
    /** @param array{name: string, email: string, password: string} $attributes */
    public function create(array $attributes): User
    {
        try {
            return DB::transaction(function () use ($attributes): User {
                $user = User::create($attributes);
                $user->ensureProfile();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => [__('messages.email_taken')],
            ]);
        }
    }
}
