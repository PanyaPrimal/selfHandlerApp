<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateThemePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array:theme'],
            'preferences.theme' => ['required', 'array:scheme,accent,accent_hex,texture,mono_numerals,motion'],
            'preferences.theme.scheme' => ['required', Rule::in(['light', 'dark', 'system'])],
            'preferences.theme.accent' => ['required', Rule::in(['forest', 'slate', 'gold', 'brick', 'custom'])],
            'preferences.theme.accent_hex' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'preferences.theme.texture' => ['required', 'boolean'],
            'preferences.theme.mono_numerals' => ['required', 'boolean'],
            'preferences.theme.motion' => ['required', Rule::in(['system', 'reduce'])],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (array_diff(array_keys($this->all()), ['preferences']) as $field) {
                    $validator->errors()->add($field, 'This field is not supported.');
                }
            },
        ];
    }
}
