<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateFinanceRecurringOperationRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['name', 'direction', 'account_id', 'category_id', 'amount', 'mandatory', 'starts_on',
            'ends_on', 'interval_months', 'month_days', 'reminder_time', 'active', 'archived'];
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'direction' => ['sometimes', Rule::in(['income', 'expense'])],
            'account_id' => ['sometimes', 'integer', 'min:1'],
            'category_id' => ['sometimes', 'integer', 'min:1'],
            'amount' => ['sometimes', 'string', 'max:32'],
            'mandatory' => ['sometimes', 'boolean'],
            'starts_on' => ['sometimes', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'interval_months' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'month_days' => ['sometimes', 'array', 'min:1', 'max:10'],
            'month_days.*' => ['required_with:month_days', 'integer', 'min:1', 'max:31', 'distinct:strict'],
            'reminder_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'active' => ['sometimes', 'boolean'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($this->all() === []) {
                $validator->errors()->add('request', __('messages.finance_operation_field_required'));
            }
        }];
    }
}
