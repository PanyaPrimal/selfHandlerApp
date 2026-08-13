<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;
use App\Models\FinanceDebt;
use Illuminate\Validation\Rule;

class StoreFinanceDebtRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['name', 'counterparty_id', 'direction', 'repayment_mode',
            'original_amount', 'currency', 'originated_on', 'deadline', 'account_id', 'category_id',
            'purchase_item_id', 'schedule', 'note'];
    }

    protected function objectAllowedKeys(): array
    {
        return ['schedule' => ['installment_amount', 'installment_count',
            'interval_months', 'monthday', 'first_due_on', 'reminder_time']];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'], 'counterparty_id' => ['required', 'integer', 'min:1'],
            'direction' => ['required', Rule::in(FinanceDebt::DIRECTIONS)],
            'repayment_mode' => ['required', Rule::in(FinanceDebt::REPAYMENT_MODES)],
            'original_amount' => ['required', 'string', 'max:32'], 'currency' => ['required', 'string', 'size:3'],
            'originated_on' => ['required', 'date_format:Y-m-d'], 'deadline' => ['present', 'nullable', 'date_format:Y-m-d'],
            'account_id' => ['present', 'nullable', 'integer', 'min:1'], 'category_id' => ['present', 'nullable', 'integer', 'min:1'],
            'purchase_item_id' => ['present', 'nullable', 'integer', 'min:1'], 'schedule' => ['present', 'nullable', 'array'],
            'schedule.installment_amount' => ['required_if:repayment_mode,fixed', 'string'], 'schedule.installment_count' => ['required_if:repayment_mode,fixed', 'integer'],
            'schedule.interval_months' => ['required_if:repayment_mode,fixed', 'integer'], 'schedule.monthday' => ['required_if:repayment_mode,fixed', 'integer'],
            'schedule.first_due_on' => ['required_if:repayment_mode,fixed', 'date_format:Y-m-d'],
            'schedule.reminder_time' => ['nullable', 'date_format:H:i'],
            'note' => ['present', 'nullable', 'string', 'max:5000'],
        ];
    }
}
