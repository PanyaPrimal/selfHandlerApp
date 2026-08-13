<?php

namespace App\Http\Requests;

use App\Models\SupplementCourseSlot;
use App\ValueObjects\WeekdayCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSupplementCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $today = now($this->user()?->calendarTimezone() ?? 'UTC')->toDateString();

        return [
            'goal_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'dose_quantity' => ['sometimes', 'required', 'string', 'max:32'],
            'dose_display_unit' => ['sometimes', 'required', Rule::in(['mg', 'g', 'ml', 'piece'])],
            'starts_on' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'ends_on' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'duration_days' => ['sometimes', 'required', 'integer', 'between:1,3660'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'is_archived' => ['sometimes', 'required', 'boolean'],
            'schedule' => ['sometimes', 'required', 'array:frequency,interval_count,weekdays,cycle,slots',
                'required_array_keys:frequency,interval_count,weekdays,cycle,slots'],
            'schedule.frequency' => ['required_with:schedule', Rule::in(['daily', 'weekly'])],
            'schedule.interval_count' => ['required_with:schedule', 'integer', 'between:1,52'],
            'schedule.weekdays' => ['sometimes', 'array', 'max:7'],
            'schedule.weekdays.*' => ['required', Rule::in(WeekdayCode::values()), 'distinct'],
            'schedule.cycle' => ['required_with:schedule', 'nullable', 'array:on_days,off_days'],
            'schedule.cycle.on_days' => ['required_with:schedule.cycle', 'integer', 'between:1,366'],
            'schedule.cycle.off_days' => ['required_with:schedule.cycle', 'integer', 'between:1,366'],
            'schedule.slots' => ['required_with:schedule', 'array', 'between:1,8'],
            'schedule.slots.*' => ['required', 'array:slot,time,intake_context'],
            'schedule.slots.*.slot' => ['required', 'regex:/^[a-z0-9_-]{1,32}$/'],
            'schedule.slots.*.time' => ['required', 'date_format:H:i'],
            'schedule.slots.*.intake_context' => ['required', Rule::in(SupplementCourseSlot::CONTEXTS)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, __('messages.unsupported_field'));
            }
            if ($this->all() === []) {
                $validator->errors()->add('request', __('messages.supplement_course_field_required'));
            }
            if ($this->has('ends_on') && $this->has('duration_days')) {
                $validator->errors()->add('ends_on', __('messages.supplement_course_end_choice'));
            }
            if (! $this->has('schedule')) {
                return;
            }
            $frequency = $this->input('schedule.frequency');
            $weekdays = $this->input('schedule.weekdays', []);
            if ($frequency === 'weekly' && $weekdays === []) {
                $validator->errors()->add('schedule.weekdays', __('messages.supplement_course_weekdays'));
            }
            if ($frequency === 'daily' && $weekdays !== []) {
                $validator->errors()->add('schedule.weekdays', __('messages.supplement_course_daily_weekdays'));
            }
            $slots = collect($this->input('schedule.slots', []));
            if ($slots->pluck('slot')->filter()->duplicates()->isNotEmpty()
                || $slots->pluck('time')->filter()->duplicates()->isNotEmpty()) {
                $validator->errors()->add('schedule.slots', __('messages.supplement_course_slots_unique'));
            }
        }];
    }
}
