<?php

namespace App\Http\Requests;

use App\Models\TrainingGoalDetail;
use Illuminate\Validation\Rule;

class TrainingGoalMutationRequest extends StrictJsonRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'target_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'kind' => [$required, Rule::in(TrainingGoalDetail::KINDS)],
            'exercise_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'activity' => ['sometimes', 'nullable', Rule::in(['running', 'cycling', 'walking', 'swimming', 'other'])],
            'workout_program_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'target_value' => [$required, 'numeric', 'gt:0', 'max:1000000'],
            'status' => ['sometimes', Rule::in(['active', 'completed', 'abandoned'])],
            'is_archived' => ['sometimes', 'boolean'],
        ];
    }

    protected function allowedKeys(): array
    {
        if ($this->isMethod('post')) {
            return [
                'name', 'description', 'target_date', 'kind', 'exercise_id', 'activity',
                'workout_program_id', 'target_value',
            ];
        }

        return ['name', 'description', 'target_date', 'target_value', 'status', 'is_archived'];
    }
}
