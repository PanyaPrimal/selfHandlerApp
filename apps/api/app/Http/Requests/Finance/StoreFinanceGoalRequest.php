<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;
use Illuminate\Validation\Rule;

class StoreFinanceGoalRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['name', 'description', 'target_date', 'kind',
            'saving_fund_id', 'debt_id', 'milestones'];
    }

    protected function nestedAllowedKeys(): array
    {
        return ['milestones' => ['target_value', 'target_date']];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'], 'description' => ['present', 'nullable', 'string', 'max:5000'],
            'target_date' => ['present', 'nullable', 'date_format:Y-m-d'], 'kind' => ['required', Rule::in(['save', 'pay_off'])],
            'saving_fund_id' => ['present', 'nullable', 'integer', 'min:1'], 'debt_id' => ['present', 'nullable', 'integer', 'min:1'],
            'milestones' => ['required', 'array', 'max:20'], 'milestones.*.target_value' => ['required', 'string'],
            'milestones.*.target_date' => ['present', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
