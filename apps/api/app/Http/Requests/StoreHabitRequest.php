<?php

namespace App\Http\Requests;

use App\Models\Habit;
use App\ValueObjects\WeekdayCode;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreHabitRequest extends StrictHabitRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'kind' => ['required', Rule::in(Habit::KINDS)],
            'mode' => ['required', Rule::in(Habit::MODES)],
            'target_value' => ['sometimes', 'nullable', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999.999'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:32'],
            'schedule_type' => ['required', Rule::in(['daily', 'weekdays'])],
            'weekdays' => ['required_if:schedule_type,weekdays', 'prohibited_unless:schedule_type,weekdays', 'array', 'min:1'],
            'weekdays.*' => ['distinct', Rule::in(WeekdayCode::values())],
            'preferred_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'routine_id' => ['sometimes', 'nullable', 'integer', Rule::exists('routines', 'id')
                ->where(fn (Builder $query): Builder => $query
                    ->where('user_id', $userId)
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->where('is_archived', false))],
            'goal_id' => ['sometimes', 'nullable', 'integer', Rule::exists('goals', 'id')
                ->where(fn (Builder $query): Builder => $query
                    ->where('user_id', $userId)
                    ->whereNull('deleted_at')
                    ->where('status', 'active')
                    ->where('is_archived', false))],
            'intention_place' => ['sometimes', 'nullable', 'string', 'max:160'],
            'two_minute_starter' => ['sometimes', 'nullable', 'string', 'max:300'],
            'limit_steps' => ['required_if:mode,stepped_limit', 'prohibited_unless:mode,stepped_limit', 'array', 'min:1', 'max:52'],
            'limit_steps.*' => ['array:effective_on,limit_value,period'],
            'limit_steps.*.effective_on' => ['required', 'date_format:Y-m-d'],
            'limit_steps.*.limit_value' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999.999'],
            'limit_steps.*.period' => ['required', Rule::in(['day', 'week'])],
        ];
    }

    protected function allowedTopLevelKeys(): array
    {
        return [
            'name', 'description', 'kind', 'mode', 'target_value', 'unit', 'schedule_type', 'weekdays',
            'preferred_time', 'starts_on', 'ends_on', 'routine_id', 'goal_id', 'intention_place',
            'two_minute_starter', 'limit_steps',
        ];
    }

    protected function afterStrictValidation(Validator $validator): void
    {
        $kind = $this->input('kind');
        $mode = $this->input('mode');
        $target = $this->input('target_value');
        $unit = $this->input('unit');

        $validPair = ($kind === Habit::KIND_HABIT && in_array($mode, [Habit::MODE_YES_NO, Habit::MODE_NUMERIC], true))
            || ($kind === Habit::KIND_ANTI_HABIT && in_array($mode, [Habit::MODE_ABSTINENCE, Habit::MODE_STEPPED_LIMIT], true));

        if (! $validPair) {
            $validator->errors()->add('kind', __('messages.habit_kind_mode'));
            $validator->errors()->add('mode', __('messages.habit_kind_mode'));
        }

        if ($mode === Habit::MODE_NUMERIC) {
            if (! is_numeric($target) || (float) $target <= 0) {
                $validator->errors()->add('target_value', __('messages.habit_target_required'));
            }
            if (! is_string($unit) || trim($unit) === '') {
                $validator->errors()->add('unit', __('messages.habit_unit_required'));
            }
        } elseif ($mode === Habit::MODE_STEPPED_LIMIT) {
            if ($target !== null) {
                $validator->errors()->add('target_value', __('messages.habit_target_prohibited'));
            }
            if (! is_string($unit) || trim($unit) === '') {
                $validator->errors()->add('unit', __('messages.habit_unit_required'));
            }
        } else {
            if ($target !== null) {
                $validator->errors()->add('target_value', __('messages.habit_target_prohibited'));
            }
            if ($unit !== null) {
                $validator->errors()->add('unit', __('messages.habit_unit_prohibited'));
            }
        }
    }
}
