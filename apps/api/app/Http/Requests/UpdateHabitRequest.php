<?php

namespace App\Http\Requests;

use App\Models\Habit;
use App\ValueObjects\WeekdayCode;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateHabitRequest extends StrictHabitRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'target_value' => ['sometimes', 'nullable', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999.999'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:32'],
            'schedule_type' => ['sometimes', Rule::in(['daily', 'weekdays'])],
            'weekdays' => ['sometimes', 'array', 'min:1'],
            'weekdays.*' => ['distinct', Rule::in(WeekdayCode::values())],
            'preferred_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
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
            'is_active' => ['sometimes', 'boolean'],
            'is_archived' => ['sometimes', 'boolean'],
        ];
    }

    protected function allowedTopLevelKeys(): array
    {
        return [
            'name', 'description', 'target_value', 'unit', 'schedule_type', 'weekdays', 'preferred_time',
            'starts_on', 'ends_on', 'routine_id', 'goal_id', 'intention_place', 'two_minute_starter',
            'is_active', 'is_archived',
        ];
    }

    protected function afterStrictValidation(Validator $validator): void
    {
        /** @var Habit|null $habit */
        $habit = $this->route('habit');
        if (! $habit) {
            return;
        }

        foreach (['kind', 'mode'] as $identity) {
            if ($this->exists($identity)) {
                $validator->errors()->add($identity, __('messages.habit_identity_locked'));
            }
        }

        if ($this->all() === []) {
            $validator->errors()->add('request', __('messages.habit_field_required'));
        }

        if ($habit->logs()->exists()) {
            foreach (['target_value', 'unit'] as $field) {
                if ($this->exists($field)) {
                    $validator->errors()->add($field, __('messages.habit_target_locked'));
                }
            }
        }

        $effectiveSchedule = $this->input('schedule_type', $habit->recurringRule?->scheduleType());
        if ($this->exists('weekdays') && $effectiveSchedule !== 'weekdays') {
            $validator->errors()->add('weekdays', __('messages.weekdays_daily'));
        }
        if ($effectiveSchedule === 'weekdays'
            && $this->input('schedule_type') === 'weekdays'
            && $habit->recurringRule?->scheduleType() !== 'weekdays'
            && ! $this->exists('weekdays')) {
            $validator->errors()->add('weekdays', __('validation.required', ['attribute' => 'weekdays']));
        }

        $starts = $this->exists('starts_on') ? $this->input('starts_on') : $habit->recurringRule?->starts_on?->format('Y-m-d');
        $ends = $this->exists('ends_on') ? $this->input('ends_on') : $habit->recurringRule?->ends_on?->format('Y-m-d');
        if (is_string($starts) && is_string($ends) && $ends < $starts) {
            $validator->errors()->add($this->exists('ends_on') ? 'ends_on' : 'starts_on', __('messages.end_after_start'));
        }

        if ($habit->mode !== Habit::MODE_NUMERIC) {
            foreach (['target_value', 'unit'] as $field) {
                if ($this->exists($field)) {
                    $validator->errors()->add($field, __('messages.habit_target_prohibited'));
                }
            }
        }
    }
}
