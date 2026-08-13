<?php

namespace App\Http\Requests;

use App\ValueObjects\WeekdayCode;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSleepPlanRequest extends StrictJsonRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'planned_bed_time' => ['required', 'date_format:H:i'],
            'planned_wake_time' => ['required', 'date_format:H:i'],
            'schedule_type' => ['required', Rule::in(['daily', 'weekdays'])],
            'weekdays' => [Rule::requiredIf($this->input('schedule_type') === 'weekdays'), 'array', 'min:1'],
            'weekdays.*' => ['distinct', Rule::in(WeekdayCode::values())],
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function allowedKeys(): array
    {
        return [
            'name', 'planned_bed_time', 'planned_wake_time', 'schedule_type', 'weekdays',
            'starts_on', 'ends_on', 'is_active',
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($this->input('planned_bed_time') === $this->input('planned_wake_time')) {
                $validator->errors()->add('planned_wake_time', __('messages.sleep_wake_differs'));
            }
            if ($this->has('weekdays') && $this->input('schedule_type') !== 'weekdays') {
                $validator->errors()->add('weekdays', __('messages.weekdays_daily'));
            }
            $starts = $this->input('starts_on');
            $ends = $this->input('ends_on');
            if (is_string($starts) && is_string($ends) && $ends < $starts) {
                $validator->errors()->add('ends_on', __('messages.end_after_start'));
            }
        }];
    }
}
