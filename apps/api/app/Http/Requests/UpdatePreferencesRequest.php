<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array:locale,theme'],
            'preferences.locale' => ['sometimes', Rule::in(config('selfhandler.profile.locales', []))],
            'preferences.theme' => [
                'sometimes',
                'array:scheme,accent,accent_hex,background,background_hex,texture,mono_numerals,motion',
            ],
            'preferences.theme.scheme' => ['required_with:preferences.theme', Rule::in(['light', 'dark', 'system'])],
            'preferences.theme.accent' => ['required_with:preferences.theme', Rule::in(['forest', 'slate', 'gold', 'brick', 'custom'])],
            'preferences.theme.accent_hex' => ['required_with:preferences.theme', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'preferences.theme.background' => ['required_with:preferences.theme', Rule::in(['paper', 'sand', 'mist', 'sage', 'custom'])],
            'preferences.theme.background_hex' => ['required_with:preferences.theme', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'preferences.theme.texture' => ['required_with:preferences.theme', 'boolean'],
            'preferences.theme.mono_numerals' => ['required_with:preferences.theme', 'boolean'],
            'preferences.theme.motion' => ['required_with:preferences.theme', Rule::in(['system', 'reduce'])],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (array_diff(array_keys($this->all()), ['preferences']) as $field) {
                    $validator->errors()->add($field, __('messages.unsupported_field'));
                }

                $preferences = $this->input('preferences');

                if (is_array($preferences) && ! array_key_exists('locale', $preferences) && ! array_key_exists('theme', $preferences)) {
                    $validator->errors()->add('preferences', __('messages.preference_required'));
                }
            },
        ];
    }
}
