<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateFinanceBudgetRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['month', 'category_id', 'limit_amount', 'currency'];
    }

    public function rules(): array
    {
        return [
            'month' => ['sometimes', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'category_id' => ['sometimes', 'integer', 'min:1'],
            'limit_amount' => ['sometimes', 'string', 'max:32'],
            'currency' => ['sometimes', Rule::exists('currencies', 'code')->where('is_active', true)],
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($this->all() === []) {
                $validator->errors()->add('request', __('messages.finance_budget_field_required'));
            }
        }];
    }
}
