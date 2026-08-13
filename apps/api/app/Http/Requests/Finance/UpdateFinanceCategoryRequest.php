<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;
use Illuminate\Validation\Validator;

class UpdateFinanceCategoryRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['name', 'parent_id', 'archived'];
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($this->all() === []) {
                $validator->errors()->add('request', __('messages.finance_category_field_required'));
            }
            if ($this->has('name') && trim($this->string('name')->toString()) === '') {
                $validator->errors()->add('name', __('validation.required', ['attribute' => 'name']));
            }
        }];
    }
}
