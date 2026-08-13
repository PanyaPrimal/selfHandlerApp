<?php

namespace App\Http\Requests;

use App\Models\SupplementIntake;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertSupplementIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::in(SupplementIntake::OUTCOMES)],
            'dose_quantity' => ['present', 'nullable', 'string', 'max:32'],
            'dose_display_unit' => ['present', 'nullable', Rule::in(['mg', 'g', 'ml', 'piece'])],
            'taken_time' => ['present', 'nullable', 'date_format:H:i'],
            'note' => ['present', 'nullable', 'string', 'max:5000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, __('messages.unsupported_field'));
            }
            $outcome = $this->input('outcome');
            $dose = $this->input('dose_quantity');
            $unit = $this->input('dose_display_unit');
            if (($dose === null) !== ($unit === null)) {
                $validator->errors()->add('dose_quantity', __('messages.supplement_intake_dose_pair'));
            }
            if ($outcome === SupplementIntake::OUTCOME_TAKEN && $this->input('taken_time') === null) {
                $validator->errors()->add('taken_time', __('messages.supplement_intake_time_required'));
            }
            if ($outcome === SupplementIntake::OUTCOME_SKIPPED
                && ($this->input('taken_time') !== null || $dose !== null || $unit !== null)) {
                $validator->errors()->add('taken_time', __('messages.supplement_intake_skip_fields'));
            }
        }];
    }
}
