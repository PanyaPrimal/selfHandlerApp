<?php

namespace App\Http\Requests;

use App\Models\WorkoutProgram;
use Illuminate\Validation\Rule;

class WorkoutSessionMutationRequest extends StrictJsonRequest
{
    public function rules(): array
    {
        $manual = $this->isMethod('post');
        $planned = $this->isMethod('put');
        $required = $manual ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:160'],
            'workout_type' => [$required, Rule::in(WorkoutProgram::TYPES)],
            'performed_on' => [$required, 'date_format:Y-m-d'],
            'outcome' => [$planned ? 'required' : 'sometimes', Rule::in(['completed', 'skipped'])],
            'started_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'duration_seconds' => ['sometimes', 'nullable', 'integer', 'between:1,86400'],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'strength' => ['sometimes', 'nullable', 'array:mode,exercises'],
            'strength.mode' => ['required_with:strength', Rule::in(['simple', 'detailed'])],
            'strength.exercises' => ['required_with:strength', 'array', 'min:1', 'max:50'],
            'strength.exercises.*' => ['array:exercise_id,sort_order,simple_weight_kg,simple_reps,note,sets'],
            'strength.exercises.*.exercise_id' => ['required', 'integer', 'min:1'],
            'strength.exercises.*.sort_order' => ['required', 'integer', 'between:0,49', 'distinct'],
            'strength.exercises.*.simple_weight_kg' => ['sometimes', 'nullable', 'numeric', 'between:0,9999.999'],
            'strength.exercises.*.simple_reps' => ['sometimes', 'nullable', 'integer', 'between:1,1000'],
            'strength.exercises.*.note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'strength.exercises.*.sets' => ['sometimes', 'array', 'max:20'],
            'strength.exercises.*.sets.*' => ['array:set_order,weight_kg,reps,rest_seconds'],
            'strength.exercises.*.sets.*.set_order' => ['required', 'integer', 'between:0,19', 'distinct'],
            'strength.exercises.*.sets.*.weight_kg' => ['required', 'numeric', 'between:0,9999.999'],
            'strength.exercises.*.sets.*.reps' => ['required', 'integer', 'between:1,1000'],
            'strength.exercises.*.sets.*.rest_seconds' => ['sometimes', 'nullable', 'integer', 'between:0,86400'],
            'endurance' => ['sometimes', 'nullable', 'array:activity,run_type,distance_m,average_heart_rate,energy_kcal'],
            'endurance.activity' => ['required_with:endurance', Rule::in(['running', 'cycling', 'walking', 'swimming', 'other'])],
            'endurance.run_type' => ['sometimes', 'nullable', Rule::in(['easy', 'tempo', 'intervals', 'long'])],
            'endurance.distance_m' => ['sometimes', 'nullable', 'integer', 'between:1,1000000'],
            'endurance.average_heart_rate' => ['sometimes', 'nullable', 'integer', 'between:30,240'],
            'endurance.energy_kcal' => ['sometimes', 'nullable', 'integer', 'between:0,100000'],
            'timed' => ['sometimes', 'nullable', 'array:activity_name'],
            'timed.activity_name' => ['sometimes', 'nullable', 'string', 'max:160'],
        ];
    }

    protected function allowedKeys(): array
    {
        $common = ['started_time', 'duration_seconds', 'note', 'strength', 'endurance', 'timed'];
        if ($this->isMethod('post')) {
            return ['name', 'workout_type', 'performed_on', ...$common];
        }
        if ($this->isMethod('put')) {
            return ['outcome', ...$common];
        }

        return ['name', 'performed_on', 'outcome', ...$common];
    }
}
