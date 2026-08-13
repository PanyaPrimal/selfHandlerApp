<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ReplaceHabitLimitStepsRequest extends StrictHabitRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'steps' => ['required', 'array', 'min:1', 'max:52'],
            'steps.*' => ['array:effective_on,limit_value,period'],
            'steps.*.effective_on' => ['required', 'date_format:Y-m-d'],
            'steps.*.limit_value' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999.999'],
            'steps.*.period' => ['required', Rule::in(['day', 'week'])],
        ];
    }

    protected function allowedTopLevelKeys(): array
    {
        return ['steps'];
    }
}
