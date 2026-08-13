<?php

namespace App\Http\Requests;

use App\Models\WorkoutProgram;
use App\ValueObjects\WeekdayCode;
use Illuminate\Validation\Rule;

class WorkoutProgramMutationRequest extends StrictJsonRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'workout_type' => [$required, Rule::in(WorkoutProgram::TYPES)],
            'intensity' => [$required, Rule::in(WorkoutProgram::INTENSITIES)],
            'planned_duration_seconds' => ['sometimes', 'nullable', 'integer', 'between:60,86400'],
            'schedule_type' => [$required, Rule::in(['daily', 'weekdays'])],
            'weekdays' => ['sometimes', 'array', 'max:7'],
            'weekdays.*' => ['distinct', Rule::in(WeekdayCode::values())],
            'preferred_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'endurance' => ['sometimes', 'nullable', 'array:activity,run_type,target_distance_m'],
            'endurance.activity' => ['required_with:endurance', Rule::in(['running', 'cycling', 'walking', 'swimming', 'other'])],
            'endurance.run_type' => ['sometimes', 'nullable', Rule::in(['easy', 'tempo', 'intervals', 'long'])],
            'endurance.target_distance_m' => ['sometimes', 'nullable', 'integer', 'between:1,1000000'],
            'timed' => ['sometimes', 'nullable', 'array:activity_name'],
            'timed.activity_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'is_active' => ['sometimes', 'boolean'],
            'is_archived' => ['sometimes', 'boolean'],
        ];
    }

    protected function allowedKeys(): array
    {
        $keys = [
            'name', 'description', 'intensity', 'planned_duration_seconds', 'schedule_type', 'weekdays',
            'preferred_time', 'starts_on', 'ends_on', 'endurance', 'timed', 'is_active', 'is_archived',
        ];
        if ($this->isMethod('post')) {
            $keys[] = 'workout_type';
            $keys = array_values(array_diff($keys, ['is_active', 'is_archived']));
        }

        return $keys;
    }
}
