<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ValidatePortableBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['backup' => ['required', 'file', 'max:'.(int) ceil(config('portability.max_archive_bytes') / 1024)]];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['backup']) as $field) {
                $validator->errors()->add($field, __('messages.unsupported_field'));
            }
        }];
    }
}
