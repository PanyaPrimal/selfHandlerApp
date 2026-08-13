<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReplaceNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'quiet_hours' => ['required', 'array:enabled,starts_at,ends_at'],
            'quiet_hours.enabled' => ['required', 'boolean'],
            'quiet_hours.starts_at' => ['required', 'date_format:H:i'],
            'quiet_hours.ends_at' => ['required', 'date_format:H:i'],
            'digest' => ['required', 'array:enabled,time'],
            'digest.enabled' => ['required', 'boolean'],
            'digest.time' => ['required', 'date_format:H:i'],
            'categories' => ['required', 'array:routine,storage,habit,sleep'],
            'categories.routine' => ['required', 'boolean'],
            'categories.storage' => ['required', 'boolean'],
            'categories.habit' => ['sometimes', 'required', 'boolean'],
            'categories.sleep' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (array_diff(array_keys($this->all()), ['quiet_hours', 'digest', 'categories']) as $field) {
                    $validator->errors()->add($field, __('messages.unsupported_field'));
                }

                if ($this->boolean('quiet_hours.enabled')
                    && $this->input('quiet_hours.starts_at') === $this->input('quiet_hours.ends_at')) {
                    $validator->errors()->add(
                        'quiet_hours.ends_at',
                        __('messages.quiet_hours_distinct'),
                    );
                }
            },
        ];
    }
}
