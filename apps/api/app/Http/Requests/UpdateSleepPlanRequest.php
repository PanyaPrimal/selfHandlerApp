<?php

namespace App\Http\Requests;

use App\Models\SleepPlan;
use App\ValueObjects\WeekdayCode;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSleepPlanRequest extends StrictJsonRequest
{
    protected function prepareForValidation(): void
    {
        $plan = $this->route('sleepPlan');
        abort_unless($plan && $plan->isOwnedBy($this->user()), 404);
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'planned_bed_time' => ['sometimes', 'date_format:H:i'],
            'planned_wake_time' => ['sometimes', 'date_format:H:i'],
            'schedule_type' => ['sometimes', Rule::in(['daily', 'weekdays'])],
            'weekdays' => ['sometimes', 'array', 'min:1'],
            'weekdays.*' => ['distinct', Rule::in(WeekdayCode::values())],
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'is_active' => ['sometimes', 'boolean'],
            'is_archived' => ['sometimes', 'boolean'],
        ];
    }

    protected function allowedKeys(): array
    {
        return [
            'name', 'planned_bed_time', 'planned_wake_time', 'schedule_type', 'weekdays',
            'starts_on', 'ends_on', 'is_active', 'is_archived',
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($this->all() === []) {
                $validator->errors()->add('request', __('messages.sleep_field_required'));

                return;
            }

            /** @var SleepPlan|null $plan */
            $plan = $this->route('sleepPlan');
            $bed = $this->input('planned_bed_time', $plan?->recurringRule?->slot_time);
            $wake = $this->input('planned_wake_time', $plan?->planned_wake_time);
            if ($bed !== null && substr((string) $bed, 0, 5) === substr((string) $wake, 0, 5)) {
                $validator->errors()->add('planned_wake_time', __('messages.sleep_wake_differs'));
            }

            $scheduleType = $this->input('schedule_type', $plan?->recurringRule?->scheduleType());
            if ($this->has('weekdays') && $scheduleType !== 'weekdays') {
                $validator->errors()->add('weekdays', __('messages.weekdays_daily'));
            }
            if ($this->input('schedule_type') === 'weekdays'
                && ! $this->has('weekdays')
                && ($plan?->recurringRule?->weekdays ?? []) === []) {
                $validator->errors()->add('weekdays', __('messages.weekdays_required'));
            }

            $starts = $this->exists('starts_on') ? $this->input('starts_on') : $plan?->recurringRule?->starts_on?->format('Y-m-d');
            $ends = $this->exists('ends_on') ? $this->input('ends_on') : $plan?->recurringRule?->ends_on?->format('Y-m-d');
            if (is_string($starts) && is_string($ends) && $ends < $starts) {
                $validator->errors()->add($this->exists('ends_on') ? 'ends_on' : 'starts_on', __('messages.end_after_start'));
            }
        }];
    }
}
