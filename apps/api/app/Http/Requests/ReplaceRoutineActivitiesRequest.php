<?php

namespace App\Http\Requests;

class ReplaceRoutineActivitiesRequest extends StrictJsonRequest
{
    public function rules(): array
    {
        return [
            'activities' => ['present', 'array', 'max:100'],
            'activities.*.id' => ['sometimes', 'integer', 'min:1'],
            'activities.*.name' => ['required', 'string', 'max:160'],
            'activities.*.sort_order' => ['required', 'integer', 'min:0'],
            'activities.*.preferred_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'activities.*.progress_total' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999999.999'],
        ];
    }

    protected function allowedKeys(): array
    {
        return ['activities'];
    }

    protected function nestedAllowedKeys(): array
    {
        return ['activities' => ['id', 'name', 'sort_order', 'preferred_time', 'progress_total']];
    }

    protected function prepareForValidation(): void
    {
        $routine = $this->route('routine');
        abort_unless($routine && $routine->isOwnedBy($this->user()), 404);
    }
}
