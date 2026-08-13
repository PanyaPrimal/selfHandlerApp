<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;
use Illuminate\Validation\Rule;

class StoreFinanceRecurringOperationRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['name', 'direction', 'account_id', 'category_id', 'amount', 'mandatory', 'starts_on',
            'ends_on', 'interval_months', 'month_days', 'reminder_time'];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'direction' => ['required', Rule::in(['income', 'expense'])],
            'account_id' => ['required', 'integer', 'min:1'],
            'category_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'string', 'max:32'],
            'mandatory' => ['required', 'boolean'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['present', 'nullable', 'date_format:Y-m-d'],
            'interval_months' => ['required', 'integer', 'min:1', 'max:12'],
            'month_days' => ['required', 'array', 'min:1', 'max:10'],
            'month_days.*' => ['required', 'integer', 'min:1', 'max:31', 'distinct:strict'],
            'reminder_time' => ['present', 'nullable', 'date_format:H:i'],
        ];
    }
}
