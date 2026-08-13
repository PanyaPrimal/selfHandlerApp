<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class StrictJsonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return list<string> */
    abstract protected function allowedKeys(): array;

    /** @return array<string, list<string>> */
    protected function nestedAllowedKeys(): array
    {
        return [];
    }

    /** @return array<string, list<string>> */
    protected function objectAllowedKeys(): array
    {
        return [];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), $this->allowedKeys()) !== []) {
                $validator->errors()->add('request', __('messages.unknown_fields'));
            }

            foreach ($this->nestedAllowedKeys() as $field => $allowed) {
                foreach ((array) $this->input($field, []) as $index => $item) {
                    if (is_array($item) && array_diff(array_keys($item), $allowed) !== []) {
                        $validator->errors()->add('request', __('messages.unknown_fields'));
                        $validator->errors()->add("{$field}.{$index}", __('messages.unknown_fields'));
                    }
                }
            }

            foreach ($this->objectAllowedKeys() as $field => $allowed) {
                $item = $this->input($field);
                if (is_array($item) && array_diff(array_keys($item), $allowed) !== []) {
                    $validator->errors()->add('request', __('messages.unknown_fields'));
                    $validator->errors()->add($field, __('messages.unknown_fields'));
                }
            }
        }];
    }
}
