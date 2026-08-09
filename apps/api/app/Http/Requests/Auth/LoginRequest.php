<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        return $this->user() === null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::guard('web')->attempt($this->only('email', 'password'))) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => User::normalizeEmail($this->input('email')),
        ]);
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Already authenticated.',
        ], 409));
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        $retryAfter = max(1, RateLimiter::availableIn($this->throttleKey()));

        throw new HttpResponseException(
            response()
                ->json(['message' => 'Too many login attempts. Please try again later.'], 429)
                ->header('Retry-After', (string) $retryAfter),
        );
    }

    private function throttleKey(): string
    {
        return 'login:'.hash('sha256', $this->string('email')->toString().'|'.($this->ip() ?? 'unknown'));
    }
}
