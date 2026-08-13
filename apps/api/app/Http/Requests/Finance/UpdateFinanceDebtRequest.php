<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;

class UpdateFinanceDebtRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['name', 'counterparty_id', 'deadline', 'account_id',
            'category_id', 'schedule', 'note', 'active', 'archived'];
    }

    protected function objectAllowedKeys(): array
    {
        return ['schedule' => ['installment_amount', 'installment_count',
            'interval_months', 'monthday', 'first_due_on', 'reminder_time']];
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'], 'counterparty_id' => ['sometimes', 'integer', 'min:1'],
            'deadline' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'account_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'schedule' => ['sometimes', 'nullable', 'array'],
            'schedule.installment_amount' => ['required_with:schedule', 'string'], 'schedule.installment_count' => ['required_with:schedule', 'integer'],
            'schedule.interval_months' => ['required_with:schedule', 'integer'], 'schedule.monthday' => ['required_with:schedule', 'integer'],
            'schedule.first_due_on' => ['required_with:schedule', 'date_format:Y-m-d'],
            'schedule.reminder_time' => ['nullable', 'date_format:H:i'],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'], 'active' => ['sometimes', 'boolean'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }
}
