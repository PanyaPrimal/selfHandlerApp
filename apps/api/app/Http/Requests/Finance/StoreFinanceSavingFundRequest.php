<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;
use App\Models\FinanceSavingFund;
use Illuminate\Validation\Rule;

class StoreFinanceSavingFundRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['name', 'fund_type', 'storage_mode', 'account_id',
            'funding_account_id', 'category_id', 'currency', 'target_mode', 'target_amount', 'deadline', 'rule', 'note'];
    }

    protected function objectAllowedKeys(): array
    {
        return ['rule' => ['top_up_mode', 'fixed_amount', 'income_percent',
            'expense_months', 'build_months', 'starts_on', 'monthday', 'reminder_time']];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'], 'fund_type' => ['required', Rule::in(FinanceSavingFund::TYPES)],
            'storage_mode' => ['required', Rule::in(FinanceSavingFund::STORAGE_MODES)], 'account_id' => ['required', 'integer', 'min:1'],
            'funding_account_id' => ['present', 'nullable', 'integer', 'min:1'], 'category_id' => ['present', 'nullable', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'], 'target_mode' => ['required', Rule::in(FinanceSavingFund::TARGET_MODES)],
            'target_amount' => ['present', 'nullable', 'string', 'max:32'], 'deadline' => ['present', 'nullable', 'date_format:Y-m-d'],
            'rule' => ['required', 'array'], 'rule.top_up_mode' => ['required', Rule::in(FinanceSavingFund::TOP_UP_MODES)],
            'rule.fixed_amount' => ['present', 'nullable', 'string'], 'rule.income_percent' => ['present', 'nullable', 'numeric'],
            'rule.expense_months' => ['present', 'nullable', 'integer'], 'rule.build_months' => ['present', 'nullable', 'integer'],
            'rule.starts_on' => ['present', 'nullable', 'date_format:Y-m-d'], 'rule.monthday' => ['present', 'nullable', 'integer'],
            'rule.reminder_time' => ['present', 'nullable', 'date_format:H:i'], 'note' => ['present', 'nullable', 'string', 'max:5000'],
        ];
    }
}
