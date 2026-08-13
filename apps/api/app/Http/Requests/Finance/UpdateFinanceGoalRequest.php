<?php

namespace App\Http\Requests\Finance;

use App\Http\Requests\StrictJsonRequest;
use Illuminate\Validation\Rule;

class UpdateFinanceGoalRequest extends StrictJsonRequest
{
    protected function allowedKeys(): array
    {
        return ['name', 'description', 'target_date', 'status', 'archived', 'milestones'];
    }

    protected function nestedAllowedKeys(): array
    {
        return ['milestones' => ['target_value', 'target_date']];
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'], 'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'target_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::in(['active', 'completed', 'abandoned'])], 'archived' => ['sometimes', 'boolean'],
            'milestones' => ['sometimes', 'array', 'max:20'], 'milestones.*.target_value' => ['required', 'string'],
            'milestones.*.target_date' => ['present', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
