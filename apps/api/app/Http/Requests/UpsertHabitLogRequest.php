<?php

namespace App\Http\Requests;

use App\Models\Habit;
use App\Models\HabitLog;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertHabitLogRequest extends StrictHabitRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::in(HabitLog::OUTCOMES)],
            'value' => ['sometimes', 'nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:999999999.999'],
            'occurred_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    protected function allowedTopLevelKeys(): array
    {
        return ['outcome', 'value', 'occurred_time', 'note'];
    }

    protected function afterStrictValidation(Validator $validator): void
    {
        /** @var Habit|null $habit */
        $habit = $this->route('habit');
        $outcome = $this->input('outcome');

        if ($habit && is_string($outcome) && ! $habit->acceptsOutcome($outcome)) {
            $validator->errors()->add('outcome', __('messages.habit_outcome_incompatible'));
        }

        $recorded = $outcome === HabitLog::OUTCOME_RECORDED;
        if ($recorded && ! $this->exists('value')) {
            $validator->errors()->add('value', __('messages.habit_value_required'));
        }
        if (! $recorded && $this->input('value') !== null) {
            $validator->errors()->add('value', __('messages.habit_value_incompatible'));
        }

        if ($outcome !== HabitLog::OUTCOME_SKIPPED && ! $this->filled('occurred_time')) {
            $validator->errors()->add('occurred_time', __('messages.habit_time_required'));
        }
        if ($outcome === HabitLog::OUTCOME_SKIPPED && $this->input('occurred_time') !== null) {
            $validator->errors()->add('occurred_time', __('messages.habit_time_skipped'));
        }
    }
}
