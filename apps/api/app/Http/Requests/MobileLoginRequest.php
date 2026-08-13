<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class MobileLoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    /** @var list<string> */
    private const INPUT_KEYS = ['email', 'password', 'device_name'];

    public function authorize(): bool
    {
        return $this->user() === null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $unknown = array_diff(array_keys($this->all()), self::INPUT_KEYS);

        $validator->after(static function (Validator $validator) use ($unknown): void {
            foreach ($unknown as $field) {
                $validator->errors()->add($field, __('messages.unsupported_field'));
            }
        });
    }

    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();
        $credentials = $this->only('email', 'password');

        if (! Auth::guard('web')->validate($credentials)) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => [__('messages.credentials_incorrect')],
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        return User::query()->where('email', $this->string('email')->toString())->firstOrFail();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => User::normalizeEmail($this->input('email')),
            'device_name' => trim((string) $this->input('device_name')),
        ]);
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'message' => __('messages.already_authenticated'),
        ], 409));
    }

    protected function failedValidation(Validator $validator): void
    {
        $exception = new ValidationException($validator);

        throw new HttpResponseException(response()->json([
            'message' => $exception->getMessage(),
            'errors' => $exception->errors(),
        ], 422, [], JSON_UNESCAPED_UNICODE));
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        $retryAfter = max(1, RateLimiter::availableIn($this->throttleKey()));

        throw new HttpResponseException(
            response()
                ->json(['message' => __('messages.too_many_login')], 429)
                ->header('Retry-After', (string) $retryAfter),
        );
    }

    private function throttleKey(): string
    {
        return 'mobile-login:'.hash(
            'sha256',
            $this->string('email')->toString().'|'.($this->ip() ?? 'unknown'),
        );
    }
}
