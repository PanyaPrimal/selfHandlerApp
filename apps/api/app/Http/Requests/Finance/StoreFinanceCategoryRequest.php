<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;
use App\Models\FinanceCategory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreFinanceCategoryRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['direction', 'parent_id', 'name'];
    }

    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::in(FinanceCategory::DIRECTIONS)],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:120'],
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($this->has('name') && trim($this->string('name')->toString()) === '') {
                $validator->errors()->add('name', __('validation.required', ['attribute' => 'name']));
            }
        }];
    }
}
