<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;
use App\Models\FinanceAccount;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateFinanceAccountRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['name', 'type', 'currency', 'archived'];
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'type' => ['sometimes', Rule::in(FinanceAccount::TYPES)],
            'currency' => ['sometimes', Rule::exists('currencies', 'code')->where('is_active', true)],
            'archived' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($this->all() === []) {
                $validator->errors()->add('request', __('messages.finance_account_field_required'));
            }
            if ($this->has('name') && trim($this->string('name')->toString()) === '') {
                $validator->errors()->add('name', __('validation.required', ['attribute' => 'name']));
            }
        }];
    }
}
