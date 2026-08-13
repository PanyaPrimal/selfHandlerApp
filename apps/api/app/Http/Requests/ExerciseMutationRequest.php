<?php

namespace App\Http\Requests;

use App\Models\Exercise;
use Illuminate\Validation\Rule;

class ExerciseMutationRequest extends StrictJsonRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:160', Rule::unique('exercises', 'name')
                ->where('user_id', $this->user()?->id)->ignore($this->route('exercise'))],
            'muscle_group' => [$required, 'string', 'max:64'],
            'equipment' => ['sometimes', 'nullable', 'string', 'max:64'],
            'exercise_type' => [$required, Rule::in([Exercise::TYPE_STRENGTH, Exercise::TYPE_MOBILITY])],
            'is_archived' => ['sometimes', 'boolean'],
        ];
    }

    protected function allowedKeys(): array
    {
        return $this->isMethod('post')
            ? ['name', 'muscle_group', 'equipment', 'exercise_type']
            : ['name', 'muscle_group', 'equipment', 'exercise_type', 'is_archived'];
    }
}
