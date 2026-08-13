<?php

namespace App\Http\Requests;

use App\Models\Habit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class StrictHabitRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }

        $habit = $this->route('habit');
        if ($habit instanceof Habit && ! $habit->isOwnedBy($this->user())) {
            abort(404);
        }

        return true;
    }

    /** @return list<string> */
    abstract protected function allowedTopLevelKeys(): array;

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), $this->allowedTopLevelKeys()) as $field) {
                $validator->errors()->add($field, __('messages.unsupported_field'));
            }

            $this->afterStrictValidation($validator);
        }];
    }

    protected function afterStrictValidation(Validator $validator): void {}

    protected function prepareForValidation(): void
    {
        $normalizable = [
            'name', 'description', 'unit', 'intention_place', 'two_minute_starter', 'note',
        ];
        $normalized = [];

        foreach ($normalizable as $field) {
            if (! $this->exists($field) || ! is_string($this->input($field))) {
                continue;
            }

            $value = trim((string) $this->input($field));
            $normalized[$field] = $value === '' && $field !== 'name' ? null : $value;
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }
}
