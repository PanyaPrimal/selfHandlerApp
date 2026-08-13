<?php

namespace App\Http\Requests;

use App\Models\Supplement;
use App\ValueObjects\SupplementQuantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class UpdateSupplementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'category' => ['sometimes', 'required', Rule::in(Supplement::CATEGORIES)],
            'form' => ['sometimes', 'required', Rule::in(Supplement::FORMS)],
            'stock_unit' => ['sometimes', 'required', Rule::in(SupplementQuantity::STOCK_UNITS)],
            'preferred_display_unit' => ['sometimes', 'required', Rule::in(SupplementQuantity::DISPLAY_UNITS)],
            'usual_dose_quantity' => ['sometimes', 'required', 'string', 'max:32'],
            'package_quantity' => ['sometimes', 'nullable', 'string', 'max:32'],
            'restock_lead_days' => ['sometimes', 'required', 'integer', 'between:0,90'],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_archived' => ['sometimes', 'required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, __('messages.unsupported_field'));
            }
            if ($this->all() === []) {
                $validator->errors()->add('request', __('messages.supplement_field_required'));
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            /** @var Supplement|null $supplement */
            $supplement = $this->route('supplement');
            if (! $supplement) {
                return;
            }
            $stockUnit = (string) $this->input('stock_unit', $supplement->stock_unit);
            $displayUnit = (string) $this->input('preferred_display_unit', $supplement->preferred_display_unit);
            try {
                if (array_key_exists('usual_dose_quantity', $this->all())) {
                    $value = SupplementQuantity::fromDisplay(
                        (string) $this->input('usual_dose_quantity'), $displayUnit, $stockUnit,
                    )->canonical();
                    if (bccomp($value, '0', 6) <= 0) {
                        throw new InvalidArgumentException;
                    }
                }
                if ($this->input('package_quantity', '__absent__') !== '__absent__'
                    && $this->input('package_quantity') !== null) {
                    $value = SupplementQuantity::fromDisplay(
                        (string) $this->input('package_quantity'), $displayUnit, $stockUnit,
                    )->canonical();
                    if (bccomp($value, '0', 6) <= 0) {
                        throw new InvalidArgumentException;
                    }
                }
                if (! SupplementQuantity::compatible($displayUnit, $stockUnit)) {
                    throw new InvalidArgumentException;
                }
            } catch (InvalidArgumentException) {
                $validator->errors()->add('preferred_display_unit', __('messages.supplement_unit_incompatible'));
            }
        }];
    }
}
