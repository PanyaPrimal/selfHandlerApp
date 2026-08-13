<?php

namespace App\Http\Requests;

class ReplaceRoutineDaySelectionsRequest extends StrictJsonRequest
{
    public function rules(): array
    {
        return [
            'morning_routine_id' => ['present', 'nullable', 'integer', 'min:1'],
            'evening_routine_id' => ['present', 'nullable', 'integer', 'min:1'],
        ];
    }

    protected function allowedKeys(): array
    {
        return ['morning_routine_id', 'evening_routine_id'];
    }
}
