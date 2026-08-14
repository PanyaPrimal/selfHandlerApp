<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RestorePortableBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'backup' => ['required', 'file', 'max:'.(int) ceil(config('portability.max_archive_bytes') / 1024)],
            'restore_token' => ['required', 'string', 'between:40,2048'],
            'confirmation' => ['required', 'string', Rule::in(['RESTORE'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['backup', 'restore_token', 'confirmation']) as $field) {
                $validator->errors()->add($field, __('messages.unsupported_field'));
            }
        }];
    }
}
