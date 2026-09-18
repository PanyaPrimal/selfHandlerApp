<?php

namespace App\Http\Requests;

use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Contracts\Validation\Validator;

class MobileRegisterRequest extends RegisterRequest
{
    public function authorize(): bool
    {
        return $this->user('sanctum') === null;
    }

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'device_name' => ['required', 'string', 'max:64'],
        ]);
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->merge(['device_name' => trim((string) $this->input('device_name'))]);
    }

    public function withValidator(Validator $validator): void
    {
        $unknown = array_diff(array_keys($this->all()), [
            'name', 'email', 'password', 'password_confirmation', 'device_name',
        ]);
        $validator->after(static function (Validator $validator) use ($unknown): void {
            foreach ($unknown as $field) {
                $validator->errors()->add($field, __('messages.unsupported_field'));
            }
        });
    }
}
