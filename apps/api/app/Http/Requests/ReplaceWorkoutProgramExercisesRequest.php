<?php

namespace App\Http\Requests;

class ReplaceWorkoutProgramExercisesRequest extends StrictJsonRequest
{
    public function rules(): array
    {
        return [
            'exercises' => ['required', 'array', 'min:1', 'max:50'],
            'exercises.*' => ['array:exercise_id,sort_order,target_sets,target_reps,starting_weight_kg,increment_kg,successes_required'],
            'exercises.*.exercise_id' => ['required', 'integer', 'min:1'],
            'exercises.*.sort_order' => ['required', 'integer', 'between:0,49', 'distinct'],
            'exercises.*.target_sets' => ['required', 'integer', 'between:1,20'],
            'exercises.*.target_reps' => ['required', 'integer', 'between:1,1000'],
            'exercises.*.starting_weight_kg' => ['required', 'numeric', 'between:0,9999.999'],
            'exercises.*.increment_kg' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'exercises.*.successes_required' => ['required', 'integer', 'between:1,20'],
        ];
    }

    protected function allowedKeys(): array
    {
        return ['exercises'];
    }

    protected function nestedAllowedKeys(): array
    {
        return ['exercises' => [
            'exercise_id', 'sort_order', 'target_sets', 'target_reps',
            'starting_weight_kg', 'increment_kg', 'successes_required',
        ]];
    }
}
