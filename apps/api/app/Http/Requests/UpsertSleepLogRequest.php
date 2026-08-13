<?php

namespace App\Http\Requests;

class UpsertSleepLogRequest extends StrictJsonRequest
{
    protected function prepareForValidation(): void
    {
        $plan = $this->route('sleepPlan');
        abort_unless($plan && $plan->isOwnedBy($this->user()), 404);
    }

    public function rules(): array
    {
        return [
            'actual_bed_date' => ['required', 'date_format:Y-m-d'],
            'actual_bed_time' => ['required', 'date_format:H:i'],
            'actual_wake_date' => ['required', 'date_format:Y-m-d'],
            'actual_wake_time' => ['required', 'date_format:H:i'],
            'quality' => ['required', 'integer', 'between:1,10'],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    protected function allowedKeys(): array
    {
        return [
            'actual_bed_date', 'actual_bed_time', 'actual_wake_date', 'actual_wake_time', 'quality', 'note',
        ];
    }
}
