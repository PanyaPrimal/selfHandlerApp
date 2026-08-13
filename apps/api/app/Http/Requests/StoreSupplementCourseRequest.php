<?php

namespace App\Http\Requests;

use App\Models\SupplementCourseSlot;
use App\ValueObjects\WeekdayCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSupplementCourseRequest extends FormRequest
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
            'supplement_id' => ['required', 'integer', 'min:1'],
            'goal_id' => ['present', 'nullable', 'integer', 'min:1'],
            'name' => ['present', 'nullable', 'string', 'max:160'],
            'dose_quantity' => ['required', 'string', 'max:32'],
            'dose_display_unit' => ['required', Rule::in(['mg', 'g', 'ml', 'piece'])],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'ends_on' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'duration_days' => ['sometimes', 'required', 'integer', 'between:1,3660'],
            'is_active' => ['required', 'boolean'],
            ...$this->scheduleRules('required'),
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateWholeRequest($validator)];
    }

    /** @return array<string, list<mixed>> */
    private function scheduleRules(string $presence): array
    {
        return [
            'schedule' => [$presence, 'array:frequency,interval_count,weekdays,cycle,slots',
                'required_array_keys:frequency,interval_count,weekdays,cycle,slots'],
            'schedule.frequency' => ['required', Rule::in(['daily', 'weekly'])],
            'schedule.interval_count' => ['required', 'integer', 'between:1,52'],
            'schedule.weekdays' => ['present', 'array', 'max:7'],
            'schedule.weekdays.*' => ['required', Rule::in(WeekdayCode::values()), 'distinct'],
            'schedule.cycle' => ['present', 'nullable', 'array:on_days,off_days'],
            'schedule.cycle.on_days' => ['required_with:schedule.cycle', 'integer', 'between:1,366'],
            'schedule.cycle.off_days' => ['required_with:schedule.cycle', 'integer', 'between:1,366'],
            'schedule.slots' => ['required', 'array', 'between:1,8'],
            'schedule.slots.*' => ['required', 'array:slot,time,intake_context'],
            'schedule.slots.*.slot' => ['required', 'regex:/^[a-z0-9_-]{1,32}$/'],
            'schedule.slots.*.time' => ['required', 'date_format:H:i'],
            'schedule.slots.*.intake_context' => ['required', Rule::in(SupplementCourseSlot::CONTEXTS)],
        ];
    }

    private function validateWholeRequest(Validator $validator): void
    {
        foreach (array_diff(array_keys($this->all()), [
            'supplement_id', 'goal_id', 'name', 'dose_quantity', 'dose_display_unit', 'starts_on',
            'ends_on', 'duration_days', 'is_active', 'schedule',
        ]) as $field) {
            $validator->errors()->add($field, __('messages.unsupported_field'));
        }
        if ($this->has('ends_on') === $this->has('duration_days')) {
            $validator->errors()->add('ends_on', __('messages.supplement_course_end_choice'));
        }
        $this->validateSchedule($validator);
    }

    private function validateSchedule(Validator $validator): void
    {
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
    }
}
