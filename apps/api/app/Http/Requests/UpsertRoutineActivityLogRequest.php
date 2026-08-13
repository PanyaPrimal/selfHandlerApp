<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class UpsertRoutineActivityLogRequest extends StrictJsonRequest
{
    protected function prepareForValidation(): void
    {
        $routine = $this->route('routine');
        $activity = $this->route('activity');
        abort_unless(
            $routine && $activity
            && $routine->isOwnedBy($this->user())
            && $activity->isOwnedBy($this->user())
            && (int) $activity->routine_id === (int) $routine->id,
            404,
        );
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['done', 'skipped'])],
            'progress_value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999.999'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    protected function allowedKeys(): array
    {
        return ['status', 'progress_value', 'note'];
    }
}
